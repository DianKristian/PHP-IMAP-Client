<?php
declare(strict_types=1);

namespace Mailbox;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConnectionAbstract.php';

// @see: https://datatracker.ietf.org/doc/html/rfc3501
class ConnectionSocket extends ConnectionAbstract {
	protected $commandTag = 0;
	
	
	public $taggedResponse;
	public $untaggedResponse;
	public $isContinuation = false;
	
	
	/**
	 * 
	 */
	public function __construct(Config $config) {
		$this->options = $config;
		
		$isSSL = $this->options['secure'];
		$socket = 'tcp';
		if ($isSSL):
			$socket = 'ssl';
		endif;
		
		$socket .= '://' . $this->options['host'] . ':';
		
		if ($this->options['port'] === null):
			$socket .= $isSSL ? 993 : 143;
		else:
			$socket .= $this->options['port'];
		endif;
		
		$options = array();
		
		if ($this->options['proxy_host'] !== null):
			$options['http'] = [];
			$options['http']['proxy'] = $this->options['proxy_host'];
			$options['http']['request_fulluri'] = true;
			$options['http']['header'] = [];
			
			if ($this->options['proxy_port'] !== null):
				$options['http']['proxy'] .= ':' . $this->options['proxy_port'];
			endif;
			
			if ($this->options['proxy_username'] !== null):
				$auth = $this->options['proxy_username'];
				if ($this->options['proxy_password'] !== null):
					$auth = ':' . $this->options['proxy_password'];
				endif;
				$options['http']['header'][] = 'Proxy-Authorization: Basic ' . \base64_encode($auth);
			endif;
		endif;
		
		$context = stream_context_create($options);
		$this->connection = stream_socket_client($socket, $errno, $error, $this->options['timeout'], STREAM_CLIENT_CONNECT, $context);
		
		if (false === is_resource($this->connection)):
			$this->connection = null;
			throw new \Error($error, $errno);
		endif;
		
		if (false === stream_set_timeout($this->connection, $this->options['timeout'])):
			throw new \Error('Failed to set timeout');
		endif;
		if (false === $this->command('CAPABILITY')):
			throw new \Error($this->getErrorText());
		endif;
		if ($this->options['secure'] === 'tls'):
			// Initiate a TLS (encrypted) session
			$this->command('STARTTLS');
		endif;
		
		$this->authenticate();
		
		if ($this->isUTF8Supported()):
			// @see: https://datatracker.ietf.org/doc/rfc5738/
			if (in_array('', $this->capabilities['UTF8'])):
			$this->command('ENABLE UTF8=ACCEPT');
			endif;
		endif;
	}
	
	
	/**
	 * 
	**/
	public function __destruct() {
		$this->disconnect();
	}
	
	
	/**
	 * 
	 */
	public function isConnected():bool {
		return is_resource($this->connection);
	}
	
	
	
	/**
	 * 
	**/
	public function disconnect():void {
		parent::disconnect();
		if ($this->isConnected()):
			@fclose($this->connection);
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
		
		@list($requestCommand, $requestArguments) = preg_split('@[ ]+@', trim($command), 2);
		$requestCommand = strtoupper($requestCommand);
		
		if (isset(self::$commands[$requestCommand])):
			
			// Valid only in not authenticated state
			if (self::$commands[$requestCommand] & self::COMMAND_NOT_AUTHENTICATED):
				if (false === isset($this->capabilities['AUTH'])):
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
		
		// Rebuild command string! Make sure command in upper string
		$command = $requestCommand;
		if ($requestArguments !== null):
			$command .= ' ' . $requestArguments;
		endif;
		
		if ($commandTag === null):
			++$this->commandTag;
			$commandTag = str_pad((string)$this->commandTag, 4, '0', STR_PAD_LEFT);
			$command = sprintf('%s %s', $commandTag, $command);
		endif;
		$command .= self::CRLF;
		
		if ($this->options['debug']):
			echo '>> ' . $command;
		endif;
		
		if (false === @fwrite($this->connection, $command)):
			throw new \Error(sprintf('Failed to write a message: %s', trim($command)));
		endif;
		
		$this->taggedResponse = new \ArrayObject;
		$this->untaggedResponse = new \ArrayObject;
		$oldUntaggedIndex = -1;
		$newUntaggedIndex = 0;
		
		$this->results = [];
		$this->errorCode = 0;
		$this->errorText = '';
		
		while (true):
			$line = @fgets($this->connection);
			if (false === $line):
				throw new \Error('Failed to read a message');
			endif;
			if ($this->options['debug']):
				echo '<< ' . $line;
			endif;
			
			@list($response['tag'], $response['code'], $response['text']) = preg_split('/\s+/u', $line, 3);
			
			// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.1
			// Status responses are OK, NO, BAD, PREAUTH and BYE.  OK, NO, and BAD 
			// can be tagged or untagged.  PREAUTH and BYE are always untagged.
			if ($response['tag'] === $commandTag):
				
				// Check tagged response first
				$this->taggedResponse['code'] = strtoupper($response['code']);
				$this->taggedResponse['text'] = trim($response['text']);
				
				// indicates successful completion of the associated command.
				if (in_array($this->taggedResponse['code'], ['OK', 'NO', 'BAD'])):
					break;
				endif;
				
				if (isset($this->untaggedResponse[$oldUntaggedIndex]) 
				&& in_array($this->untaggedResponse[$oldUntaggedIndex]['code'], ['NO', 'BAD', 'PREAUTH', 'BYE'])):
					$this->taggedResponse['code'] = $this->untaggedResponse[$oldUntaggedIndex]['code'];
					$this->taggedResponse['text'] = trim($this->untaggedResponse[$oldUntaggedIndex]['text']);
					break;
				endif;
			
			// 
			elseif ($response['tag'] === '*'):				
				if ($response['code'] === 'CAPABILITY'):
					// Special response: CAPABILITY
					$this->capabilities = $this->parseCapability(trim($response['text']));
				else:
					//var_dump($response);
					$this->untaggedResponse[$newUntaggedIndex] = [
						'code' => strtoupper($response['code']), 
						'text' => trim($response['text']),
						'line' => new \ArrayObject
					];
					$oldUntaggedIndex = $newUntaggedIndex;
					++$newUntaggedIndex;
				endif;
			
			// 	
			elseif ($response['tag'] === '+'):
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.5
				$this->isContinuation = true;
				break;
			
			// 	
			else:
				if (isset($this->untaggedResponse[$oldUntaggedIndex])):
					if (in_array($this->untaggedResponse[$oldUntaggedIndex]['code'], ['NO', 'BAD', 'PREAUTH', 'BYE'])):
						$this->taggedResponse['code'] = $this->untaggedResponse[$oldUntaggedIndex]['code'];
						$this->taggedResponse['text'] = trim($this->untaggedResponse[$oldUntaggedIndex]['text']);
						break;
					endif;
					$this->untaggedResponse[$oldUntaggedIndex]['line'][] = $line;
				endif;
			endif;
		endwhile;
		
		if ($this->isContinuation):
			return true;
		endif;
		
		if ($this->taggedResponse['code'] === 'BAD'):
			throw new \Error($this->getErrorText());
		endif;
		
		if ($this->taggedResponse['code'] !== 'OK'):
			return false;
		endif;
		
		if (in_array($requestCommand, ['SELECT', 'EXAMINE'])):
			$this->isSelected = true;
			$this->selectedFolder = $requestArguments;
		elseif ($requestCommand === 'CLOSE'):
			$this->isSelected = false;
			$this->selectedFolder = null;
		elseif ($requestCommand === 'STARTTLS'):
			$this->getCryptoMethod();
		endif;
		return true;
	}
	
	
	/**
	 * 
	**/
	public function getResults() {
		$results = [];
		
		for ($index = 0; $index < $this->untaggedResponse->count(); ++$index):
			switch ($this->untaggedResponse[$index]['code']):
				
				// Indicates an information-only message; the nature of the information MAY be indicated by a response code.
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.1.1
				case 'OK':
				
				// Indicates an operational error message from the server.
				// https://datatracker.ietf.org/doc/html/rfc3501#section-7.1.2
				case 'NO':
				
				// Indicates an error message from the server.
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.1.3
				case 'BAD':
				
				// Indicates that the connection has already been authenticated by external means; thus no LOGIN command is needed.
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.1.4
				case 'PREAUTH':
				
				// Indicates that the server is about to close the connection.
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.1.5
				case 'BYE':
					if (false === (bool)preg_match('@\[([^\]]+)\]@', $this->untaggedResponse[$index]['text'], $match)):
						break;
					endif;
					@list($key, $value) = preg_split('@[ ]+@', $match[1], 2);
					if ($value === null):
						$results[$key] = true;
					elseif ((bool)preg_match('@^[0-9]+$@', $value)):
						$results[$key] = (int)$value;
					elseif (is_numeric($value)):
						$results[$key] = (float)$value;
					elseif ((bool)preg_match('@^\(([^\)]*)\)$@', $value, $match)):
						$results[$key] = new \ArrayObject(empty($match[1]) ? [] : preg_split('@[ ]+@', $match[1]));
					else:
						$results[$key] = $value;
					endif;
					break;
				
				// Return capability listing contains a space-separated listing of capability names that the server supports.
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.1
				case 'CAPABILITY':
				
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.4
				case 'STATUS':
				
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.5
				case 'SEARCH':
				
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.6
				case 'FLAGS':
					$method = 'parse' . ucfirst(strtolower($this->untaggedResponse[$index]['code']));
					$results[$this->untaggedResponse[$index]['code']] = $this->$method($this->untaggedResponse[$index]['text']);
					break;
				
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.2
				case 'LIST':
				
				// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.3
				case 'LSUB':
					// Noinferiors
					if (false === isset($results[$this->untaggedResponse[$index]['code']])):
						$results[$this->untaggedResponse[$index]['code']] = new \ArrayObject;
					endif;
					$results[$this->untaggedResponse[$index]['code']][] = $this->parseList($this->untaggedResponse[$index]['text']);
					break;
				
				case (bool)preg_match('@^([0-9]+)$@', $this->untaggedResponse[$index]['code']):
					@list($key, $value) = preg_split('@[ ]+@', $this->untaggedResponse[$index]['text'], 2);
					
					// EXPUNGE
					// Reports that the specified message sequence number has been permanently removed from the mailbox.
					// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.4.1
					if ($key === 'EXPUNGE'):
						if (false === isset($results['EXPUNGE'])):
							$results['EXPUNGE'] = [];
						endif;
						$results['EXPUNGE'][] = (int)$this->untaggedResponse[$index]['code'];
					
					// EXISTS
					// Reports the number of messages in the mailbox.
					// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.3.1
					
					// RECENT
					// Reports the number of messages with the \Recent flag set.
					// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.3.2
					elseif (in_array($key, ['EXISTS', 'RECENT'])):
						$results[$key] = (int)$this->untaggedResponse[$index]['code'];
						
					// FETCH
					// Returns data about a message to the client. The data are pairs of data item names and their values in parentheses.
					// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.4.2
					elseif ($key === 'FETCH'):
						$sequenceNumber = (int)$this->untaggedResponse[$index]['code'];
						$results[$sequenceNumber] = $this->parseFetchResponse($value, $this->untaggedResponse[$index]['line']);
					endif;
				break;
			endswitch;
		endfor;
		/*
		*/
		
		return $results;
	}
	
	
	
	/**
	 * Indicates a SASL authentication mechanism to the server.
	 * If the server supports the requested authentication mechanism, it performs an authentication protocol 
	 * exchange to authenticate and identify the client.
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.2
	 */
	public function authenticate(): bool {
		
		if (false === is_array($this->capabilities['AUTH'])):
			$this->capabilities['AUTH'] = [$this->capabilities['AUTH']];
		endif;
		
		if (false === isset($this->options['auth_type'])):
			return $this->login($this->options['username'], $this->options['password']);
		endif;
		
		/*
		if (false === in_array($this->options['auth_type'], $this->capabilities['AUTH'])):
			throw new \Error(sprintf('Unsupported authentication mechanism: %s.', $this->options['auth_type']));
		endif;
		*/
		
		$username = $this->toUTF8($this->options['username']);
		$password = $this->toUTF8($this->options['password']);
		switch ($this->options['auth_type']):
			case 'PLAIN':
				//Format from https://tools.ietf.org/html/rfc4616#section-2
				// https://datatracker.ietf.org/doc/html/rfc4959
				$commands = array();
				$commands[0] = 'AUTHENTICATE PLAIN';
				if (isset($this->capabilities['SASL-IR'])):
					$commands[0] .= ' ' .  base64_encode(sprintf("\x0%s\x0%s", $username, $password));
				else:
					$commands[1] = base64_encode(sprintf("\x0%s\x0%s", $username, $password));
				endif;
			break;
			
			case 'LOGIN':
				$commands = array();
				$commands[] = 'AUTHENTICATE LOGIN';
				$commands[] = base64_encode($this->toUTF8($this->options['username']));
				$commands[] = base64_encode($this->toUTF8($this->options['password']));
			break;
			
			case 'XOAUTH':
				$commands = array();
				$commands[] = sprintf("AUTHENTICATE XOAUTH user=%s\001auth=Bearer %s\001\001", 
					$this->toUTF8($this->options['username']), 
					$this->toUTF8($this->options['password'])
				);
			break;
			
			case 'XOAUTH2':
			case 'XOAUTHBEARER':
			case 'PLAIN-CLIENTTOKEN':
			case 'CRAM-MD5':
				// @todo
			default:
				throw new \Error(sprintf('Unsupported authentication mechanism: %s.', $this->options['auth_type']));
			break;
		endswitch;
		
		foreach ($commands as $command):
			$result = $this->command($command, $commandTag);
			if (false === $result):
				break;
			endif;
		endforeach;
		/*
		if ($result):
			$this->parseCapabilities();
		endif;
		*/
		return $result;
	}
	
	
	/**
	 * 
	 */
	public function getErrorCode() {
		if ($this->taggedResponse['code'] !== 'OK'):
			return $this->taggedResponse['code'];
		endif;
	}
	
	
	/**
	 * 
	 */
	public function getErrorText() {
		if ($this->getErrorCode()):
			return $this->taggedResponse['text'];
		endif;
		return '';
	}
	
	
	/**
	 * 
	 */
	protected function getCryptoMethod():void {
		if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')):
			$cryptoType = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
		elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')):
			$cryptoType = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
		elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')):
			$cryptoType = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
		else:
			$cryptoType = STREAM_CRYPTO_METHOD_TLS_CLIENT;
		endif;
		
		// stream_socket_enable_crypto return bool or 0 if there isn't enough data and you should try again (only for non-blocking sockets).
		if (true !== stream_socket_enable_crypto($this->connection, true, $cryptoType)):
			throw new \Error('Failed to enable TLS');
		endif;
	}
}

?>