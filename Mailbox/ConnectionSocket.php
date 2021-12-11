<?php
declare(strict_types=1);

namespace Mailbox;

// @see: https://datatracker.ietf.org/doc/html/rfc3501
class ConnectionSocket extends ConnectionAbstract {
	protected $commandTag = 0;	
	
	/**
	 * Constructor
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
			$options['http']['proxy'] = 'tcp://' . $this->options['proxy_host'];
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
			throw new \ErrorException($error, $errno);
		endif;
		
		if (false === stream_set_timeout($this->connection, $this->options['timeout'])):
			throw new \ErrorException('Failed to set timeout');
		endif;
		if (false === $this->command('CAPABILITY')):
			throw new \ErrorException($this->errorText);
		endif;
		if ($this->options['secure'] === 'tls'):
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
	public function command(string $command, ?string &$commandTag = null):bool {
		if (false === $this->isConnected()):
			return false;
		endif;
		
		$hasCrlf = true;
		$hasCrlf = $hasCrlf && strpos($command, "\n") !== false;
		$hasCrlf = $hasCrlf && strpos($command, "\r") !== false;
		
		if ($hasCrlf):
			throw new ErrorException('Parameter 1 are not allowed to contain line break characters');
		endif;
		
		@list($requestCommand, $requestArguments) = preg_split('@[ ]+@', trim($command), 2);
		$requestCommand = strtoupper($requestCommand);
		
		if (isset(self::$commands[$requestCommand])):
			
			// Valid only in not authenticated state
			if (self::$commands[$requestCommand] & self::COMMAND_NOT_AUTHENTICATED):
				if (false === isset($this->capabilities['AUTH'])):
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
			throw new \ErrorException(sprintf('Failed to write a message: %s', trim($command)));
		endif;
		
		$this->results = [];
		$this->errorCode = null;
		$this->errorText = '';
		
		while (true):
			$line = @fgets($this->connection);
			if (false === $line):
				throw new \ErrorException('Failed to read a message');
			endif;
			if ($this->options['debug']):
				echo '<< ' . $line;
			endif;
			$this->parseResponse($line, $responseTag);
			if ($commandTag === $responseTag):
				break;
			endif;
		endwhile;
		
		if (isset($this->results['FETCH'])):
			foreach ($this->results['FETCH'] as $sequenceNumber => $data):
				$this->results['FETCH'][$sequenceNumber] = $this->parseFetchResponse($data);
			endforeach;
		endif;
		
		if ($this->isContinuation):
			return true;
		endif;
		
		if ($this->errorCode === 'BAD'):
			throw new \Error($this->errorText);
		endif;
		
		if ($this->errorCode !== 'OK'):
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
			throw new \ErrorException('Failed to enable TLS');
		endif;
	}
}

?>