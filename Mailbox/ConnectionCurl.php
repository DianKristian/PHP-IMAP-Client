<?php
// https://www.rfc-editor.org/rfc/rfc5092
declare(strict_types=1);

namespace Mailbox;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConnectionAbstract.php';

// @see: https://datatracker.ietf.org/doc/html/rfc3501
class ConnectionCurl extends ConnectionAbstract {
	protected $rawResponse = [];
	protected $results = [];
	protected $errorCode;
	protected $errorText = '';
	
	/**
	 * 
	 */
	public function __construct(Config $config) {
		if (false === extension_loaded('curl')):
			if (false === function_exists('dl')):
				throw new Error('curl extension not installed');
			endif;
			$prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php' : '';
			if (false === @dl($prefix . 'curl.' . PHP_SHLIB_SUFFIX)):
				throw new Error('Load curl extension failed');
			endif;
		endif;
		
		$this->options = $config;
		$this->connection = curl_init();
		
		$this->curlOptions[CURLOPT_FOLLOWLOCATION] = true;
		$this->curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
		$this->curlOptions[CURLOPT_SSL_VERIFYHOST] = false;
		$this->curlOptions[CURLOPT_RETURNTRANSFER] = true;
		//$this->curlOptions[CURLOPT_FAILONERROR] = false;
		$this->curlOptions[CURLOPT_CONNECTTIMEOUT] = $config['timeout'];
		$this->curlOptions[CURLOPT_TIMEOUT] = $config['timeout'];
		$this->curlOptions[CURLOPT_WRITEFUNCTION] = function($curlHandle, $string) {
			$size = mb_strlen($string);
			if ($size > 0):
				$this->rawResponse[] = $string;
			endif;
			return $size;
		};
		
		if ($config['debug']):
			$this->curlOptions[CURLOPT_VERBOSE] = true;
		endif;
		
		$this->curlOptions[CURLOPT_URL] = 'imap';
		if ($config['secure']):
			$this->curlOptions[CURLOPT_URL] .= 's';
		endif;
		$this->curlOptions[CURLOPT_URL] .= '://' . $config['host'];
		
		if ($config['port'] === null):
			$config['port'] = $config['secure'] ? 993 : 143;
		endif;
		$this->curlOptions[CURLOPT_PORT] = $config['port'];
		
		if ($config['proxy_host'] !== null):
			$this->curlOptions[CURLOPT_PROXY] = $config['proxy_host'];
			if ($config['proxy_port'] !== null):
				$this->curlOptions[CURLOPT_PROXY] .= ':' . $config['proxy_port'];
			endif;
			if ($config['proxy_username'] !== null):
				$this->curlOptions[CURLOPT_PROXYUSERPWD] = $config['proxy_username'];
				if ($config['proxy_password'] !== null):
					$this->curlOptions[CURLOPT_PROXYUSERPWD] = ':' . $config['proxy_password'];
				endif;
			endif;
		endif;
		
		if (false === $this->command('CAPABILITY')):
			throw new \Error($this->getErrorText());
		endif;
		
		if (isset($this->capabilities['STARTTLS'])):
			// Initiate a TLS (encrypted) session
			$this->command('STARTTLS');
		endif;
		
		$this->authenticate();
	}
	
	
	
	/**
	 * 
	**/
	public function isConnected() {
		$isCurlHandle = isset($this->connection);
		if (version_compare(PHP_VERSION, '8.0.0', '<')):
			$isCurlHandle = $isCurlHandle && is_resource($this->connection);
			$isCurlHandle = $isCurlHandle && get_resource_type($this->connection) === 'curl';
		else:
			$isCurlHandle = $isCurlHandle && $this->connection instanceof \CurlHandle;
		endif;
		return $isCurlHandle;
	}
	
	
	/**
	 * 
	**/
	public function disconnect():void {
		parent::disconnect();
		if ($this->isConnected()):
			@curl_close($this->connection);
			$this->connection = null;
		endif;
	}
	
	
	/**
	 * 
	 */
	public function command(string $command, ?string &$commandTag = null): bool {
		if (false === $this->isConnected()):
			return false;
		endif;
		
		$hasCrlf = true;
		$hasCrlf = $hasCrlf && strpos($command, "\n") !== false;
		$hasCrlf = $hasCrlf && strpos($command, "\r") !== false;
		
		if ($hasCrlf):
			throw new ValueError('Parameter 1 are not allowed to contain line break characters');
		endif;
		
		@list($requestCommand, $requestArguments) = preg_split('@[ ]+@u', trim($command), 2);
		$requestCommand = strtoupper($requestCommand);
		$isUid = $requestCommand === 'UID';
		if ($isUid):
			@list($requestCommand, $requestArguments) = preg_split('@[ ]+@u', $requestArguments, 2);
		endif;
		
		if (isset(self::$commands[$requestCommand])):
			// Valid only in not authenticated state
			if (self::$commands[$requestCommand] & self::COMMAND_NOT_AUTHENTICATED):
				if ($this->isAuthenticated()):
					throw new \Error('The state already authenticated');
				endif;
				if ($requestCommand === 'AUTHENTICATE'):
					$arguments = preg_split('@[ ]+@', $requestArguments, 2);
					if (false === in_array($arguments[0], $this->capabilities['AUTH'])):
						throw new \Error(sprintf('%s authentication mechanism not supported by server', $arguments[0]));
					endif;
				elseif ($requestCommand === 'LOGIN'):
					if (isset($this->capabilities['LOGINDISABLED'])):
						throw new \Error('Using LOGIN not permitted by server');
					endif;
				endif;
				
			// Valid only in authenticated state
			elseif (self::$commands[$requestCommand] & self::COMMAND_AUTHENTICATED):
				if (false === $this->isAuthenticated()):
					throw new \Error(sprintf('Using %s method valid only in authenticated state', $requestCommand));
				endif;
				
			// Valid only when in selected state
			elseif (self::$commands[$requestCommand] & self::COMMAND_SELECTED):
				if (false === $this->isSelected()):
					throw new \Error(sprintf('No mailbox selected! Please, use SELECT method first to use %s method', $requestCommand));
				endif;
			endif;
		endif;
		
		$this->results = [];
		$this->errorCode = 0;
		$this->errorText = '';
		
		if ($requestCommand === 'FETCH'):
			// Format: FETCH <sequence_number> <items> | (<items> <items> ...)
			if ($requestArguments === null):
				throw new \Error('Bad command');
			endif;
			
			@list($requestSequence, $requestItemString) = preg_split('@[ ]+@', $requestArguments, 2);
			
			if ($requestItemString === null):
				throw new \Error('Bad command');
			endif;
			
			if ((bool)preg_match('@\(([^\)]+)\)@', $requestItemString, $match)):
				$requestItemData = preg_split('@[ ]+@', trim($match[1]));
			else:
				$requestItemData = preg_split('@[ ]+@', trim($requestItemString));
			endif;
			
			$results = [];
			
			foreach ($requestItemData as $each):
				$each = strtoupper($each);
				// When using cURL, the only wya to get contents only via URL
				// @see: https://www.rfc-editor.org/rfc/rfc5092
				if (preg_match('@(BODY|BODY\.PEEK)\[([^\]]*)\](?:\<([0-9]+)\.([0-9]+)\>)?@', $each, $match)):
					$this->command(sprintf('%sFETCH %s UID', $isUid ? 'UID ': '', $requestSequence));
					foreach ($this->results as $sequence => $item):
						if (false === (isset($item['UID']) && $item['UID'] > 0)):
							continue;
						endif;
						$url = $this->curlOptions[CURLOPT_URL] . '/' . urlencode($this->selectedFolder) . ';UID=' . $item['UID'];
						$responseField = 'BODY[';
						if (false === empty($match[2])):
							$url .= ';SECTION=' . $match[2];
							$responseField .= $match[2];
						endif;
						$responseField .= ']';
						if (false === empty($match[3])):
							$url .= ';PARTIAL=' . $match[3] . '.' . $match[4];
							$responseField .= '<' . $match[3] . '>';
						endif;
						$this->rawResponse = [];
						curl_reset($this->connection);
						curl_setopt_array($this->connection, $this->curlOptions);
						curl_setopt($this->connection, CURLOPT_URL, $url);
						if (false === curl_exec($this->connection)):
							$this->errorCode = curl_errno($this->connection);
							$this->errorText = curl_error($this->connection);
							return false;
						endif;
						if (false === isset($results[$sequence])):
							$results[$sequence] = [];
						endif;
						$results[$sequence][$responseField] = implode('', $this->rawResponse);
					endforeach;
				else:
					$this->rawResponse = [];
					curl_reset($this->connection);
					curl_setopt_array($this->connection, $this->curlOptions);
					curl_setopt($this->connection, CURLOPT_CUSTOMREQUEST, sprintf('FETCH %s %s', $requestSequence, $each));
					if (false === curl_exec($this->connection)):
						$this->errorCode = curl_errno($this->connection);
						$this->errorText = curl_error($this->connection);
						return false;
					endif;
					$countRawResponse = count($this->rawResponse);
					for ($i = 0; $i < $countRawResponse; ++$i):
						$response = preg_split('@[ ]+@u', $this->rawResponse[$i], 4);
						$sequence = $response[1];
						if (false === isset($results[$sequence])):
							$results[$sequence] = [];
						endif;
						$results[$sequence] = array_merge($results[$sequence], $this->parseFetchResponse(trim($response[3]), new \ArrayObject));
					endfor;
				endif;
			endforeach;
			$this->results = $results;
		else:
			$this->rawResponse = [];
			curl_reset($this->connection);
			curl_setopt_array($this->connection, $this->curlOptions);
			curl_setopt($this->connection, CURLOPT_CUSTOMREQUEST, $command);
			if (false === curl_exec($this->connection)):
				$this->errorCode = curl_errno($this->connection);
				$this->errorText = curl_error($this->connection);
				return false;
			endif;
			$this->results = [];
			
			$countRawResponse = count($this->rawResponse);
			for ($i = 0; $i < $countRawResponse; ++$i):
				list($responseTag, $responseCode, $responseText) = preg_split('@[ ]+@u', $this->rawResponse[$i], 3);
				if ($responseTag === '*'):
					switch ($responseCode):
						case 'OK':
						case 'NO':
						case 'BAD':
						case 'PREAUTH':
						case 'BYE':
							if (false === (bool)preg_match('@\[([^\]]+)\]@', $responseText, $match)):
								break;
							endif;
							@list($key, $value) = preg_split('@[ ]+@', $match[1], 2);
							if ($value === null):
								$this->results[$key] = true;
							elseif ((bool)preg_match('@^[0-9]+$@', $value)):
								$this->results[$key] = (int)$value;
							elseif (is_numeric($value)):
								$this->results[$key] = (float)$value;
							elseif ((bool)preg_match('@^\(([^\)]*)\)$@', $value, $match)):
								$this->results[$key] = new \ArrayObject(empty($match[1]) ? [] : preg_split('@[ ]+@', $match[1]));
							else:
								$this->results[$key] = $value;
							endif;
							break;
						case 'CAPABILITY':
							// Special response: CAPABILITY
							$this->capabilities = $this->parseCapability(trim($responseText));
							break;
						case 'STATUS':
						case 'SEARCH':
						case 'FLAGS':
							$method = 'parse' . ucfirst(strtolower($responseCode));
							$this->results[$responseCode] = $this->$method($responseText);
							break;
						case 'LIST':
						case 'LSUB':
							// Noinferiors
							if (false === isset($this->results[$responseCode])):
								$this->results[$responseCode] = new \ArrayObject;
							endif;
							$this->results[$responseCode][] = $this->parseList(trim($responseText));
							break;
						case (bool)preg_match('@^([0-9]+)$@', $responseCode):
							@list($key, $value) = preg_split('@[ ]+@', trim($responseText), 2);
							if ($key === 'EXPUNGE'):
								if (false === isset($this->results['EXPUNGE'])):
									$this->results['EXPUNGE'] = [];
								endif;
								$this->results['EXPUNGE'][] = (int)$responseCode;
							elseif (in_array($key, ['EXISTS', 'RECENT'])):
								$this->results[$key] = (int)$responseCode;
							endif;
					endswitch;
				elseif ($responseTag === '+'):
					$this->isContinuation = true;
					break;
				elseif ($responseTag === $commandTag):
					$responseCode = strtoupper($responseCode);
					$responseText = trim($responseText);
					// indicates successful completion of the associated command.
					if (in_array($responseCode, ['OK', 'NO', 'BAD'])):
						break;
					endif;
				else:
				endif;
			endfor;
		endif;
		
		if (in_array($requestCommand, ['SELECT', 'EXAMINE'])):
			$this->isSelected = true;
			$this->selectedFolder = trim($requestArguments, '"');
		elseif ($requestCommand === 'AUTHENTICATE'):
			$this->command('CAPABILITY');
		elseif ($requestCommand === 'CLOSE'):
			$this->isSelected = false;
			$this->selectedFolder = null;
		elseif ($requestCommand === 'STARTTLS'):
			//$this->getCryptoMethod();
		endif;
		
		return true;
	}
	
	
	/**
	 * 
	 */
	public function getErrorCode() {
		return $this->errorCode;
	}
	
	
	/**
	 * 
	 */
	public function getErrorText() {
		return $this->errorText;
	}
	
	
	
	/**
	 * 
	**/
	public function getResults() {
		return $this->results;
	}
}

?>