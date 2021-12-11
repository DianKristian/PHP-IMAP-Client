<?php
// https://www.rfc-editor.org/rfc/rfc5092
declare(strict_types=1);

namespace Mailbox;

// @see: https://datatracker.ietf.org/doc/html/rfc3501
class ConnectionCurl extends ConnectionAbstract {
	
	/**
	 * Constructor
	 */
	public function __construct(Config $config) {
		if (false === extension_loaded('curl')):
			if (false === function_exists('dl')):
				throw new \ErrorException('curl extension not installed');
			endif;
			$prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php' : '';
			if (false === @dl($prefix . 'curl.' . PHP_SHLIB_SUFFIX)):
				throw new \ErrorException('Load curl extension failed');
			endif;
		endif;
		
		$this->options = $config;
		$this->connection = curl_init();
		// CURLOPT_LOGIN_OPTIONS
		$this->curlOptions[CURLOPT_FOLLOWLOCATION] = true;
		$this->curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
		$this->curlOptions[CURLOPT_SSL_VERIFYHOST] = false;
		$this->curlOptions[CURLOPT_RETURNTRANSFER] = true;
		$this->curlOptions[CURLOPT_FRESH_CONNECT] = false;
		$this->curlOptions[CURLOPT_FAILONERROR] = true;
		$this->curlOptions[CURLOPT_TCP_KEEPALIVE] = true;
		$this->curlOptions[CURLOPT_CONNECTTIMEOUT] = $config['timeout'];
		$this->curlOptions[CURLOPT_TIMEOUT] = $config['timeout'];
		$this->curlOptions[CURLOPT_HEADERFUNCTION] = [$this, 'parseResponseCallback'];
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
					$this->curlOptions[CURLOPT_PROXYUSERPWD] .= ':' . $config['proxy_password'];
				endif;
			endif;
		endif;
		
		if (false === $this->command('CAPABILITY')):
			throw new \ErrorException($this->errorText);
		endif;
		
		if (isset($this->capabilities['STARTTLS'])):
			// Initiate a TLS (encrypted) session
			$this->command('STARTTLS');
		endif;
		
		$this->authenticate();
		
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$this->command('ENABLE UTF8=ACCEPT');
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$this->command('ENABLE UTF8=ALL');
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$this->command('ENABLE UTF8=ONLY');
			endif;
		endif;
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
			throw new ErrorException('Parameter 1 are not allowed to contain line break characters');
		endif;
		
		@list($requestCommand, $requestArguments) = preg_split('@[ ]+@u', trim($command), 2);
		$requestCommand = strtoupper($requestCommand);
		
		if (isset(self::$commands[$requestCommand])):
			// Valid only in not authenticated state
			if (self::$commands[$requestCommand] & self::COMMAND_NOT_AUTHENTICATED):
				if ($this->isAuthenticated()):
					throw new \ErrorException('The state already authenticated');
				endif;
				if ($requestCommand === 'AUTHENTICATE'):
					$arguments = preg_split('@[ ]+@', $requestArguments, 2);
					if (false === in_array($arguments[0], $this->capabilities['AUTH'])):
						throw new \ErrorException(sprintf('%s authentication mechanism not supported by server', $arguments[0]));
					endif;
				elseif ($requestCommand === 'LOGIN'):
					if (isset($this->capabilities['LOGINDISABLED'])):
						throw new \ErrorException('Using LOGIN not permitted by server');
					endif;
				endif;
				
			// Valid only in authenticated state
			elseif (self::$commands[$requestCommand] & self::COMMAND_AUTHENTICATED):
				if (false === $this->isAuthenticated()):
					throw new \ErrorException(sprintf('Using %s method valid only in authenticated state', $requestCommand));
				endif;
				
			// Valid only when in selected state
			elseif (self::$commands[$requestCommand] & self::COMMAND_SELECTED):
				if (false === $this->isSelected()):
					throw new \ErrorException(sprintf('No mailbox selected! Please, use SELECT method first to use %s method', $requestCommand));
				endif;
			endif;
		endif;
		
		$this->results = [];
		$this->errorCode = 0;
		$this->errorText = '';
		curl_reset($this->connection);
		curl_setopt_array($this->connection, $this->curlOptions);
		curl_setopt($this->connection, CURLOPT_CUSTOMREQUEST, $command);
		curl_exec($this->connection);
		
		if (isset($this->results['FETCH'])):
			foreach ($this->results['FETCH'] as $sequenceNumber => $data):
				$this->results['FETCH'][$sequenceNumber] = $this->parseFetchResponse($data);
			endforeach;
		endif;
		
		if ($this->isContinuation):
			return true;
		endif;
		
		if ($this->errorCode === 'BAD'):
			throw new \ErrorException($this->errorText);
		endif;
		
		if ($this->errorCode !== 'OK'):
			return false;
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
	protected function parseResponseCallback($curlHandle, string $line):int {
		$this->parseResponse($line);
		return strlen($line);
	}
}

?>