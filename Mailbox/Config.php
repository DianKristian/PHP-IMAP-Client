<?php
declare(strict_types=1);

namespace Mailbox;

class Config extends \ArrayObject {
	private $defaults = [
		'host' 							=> null, 
		'port' 							=> 143, 
		'username' 						=> null, 
		'password' 						=> null, 
		'auth_type' 					=> null, 
		'secure' 						=> null,
		'timeout' 						=> 30,
		'debug' 						=> false,
		'proxy_host' 					=> null,
		'proxy_port' 					=> null,
		'proxy_username' 				=> null,
		'proxy_password' 				=> null,
		'ssl_peer_name' 				=> false,
		'ssl_verify_peer_name' 			=> false,
		'ssl_allow_self_signed' 		=> false,
		'ssl_cafile' 					=> null,
		'ssl_capath' 					=> null,
		'ssl_local_cert' 				=> null,
		'ssl_local_pk' 					=> null,
		'ssl_passphrase' 				=> null,
		'ssl_verify_depth' 				=> 0,
		'ssl_chipers' 					=> null,
		'ssl_capture_peer_cert' 		=> false,
		'ssl_capture_peer_cert_chain' 	=> false,
		'ssl_sni_enabled' 				=> false,
		'ssl_disable_compression' 		=> false,
		'ssl_peer_fingerprint' 			=> false,
		'ssl_security_level' 			=> false,
	];
	
	public function __construct(array $config = []) {
		parent::__construct(array_merge($this->defaults, $config));
	}
	
	public function offsetSet($index, $newval) {
		$key = strtolower($key);
		
		if (false === array_key_exists($key, $this->defaults)):
			throw new ValueError(sprintf('Unknown configuration: %s', $key));
		endif;
		
		switch ($key):
			case 'port':
			case 'timeout':
			case 'ssl_verify_depth':
				if (false === is_int($value)):
					throw new TypeError(sprintf('Argument 2 passed must be of the type integer, %s given', gettype($value)));
				endif;
			break;
			
			case 'debug':
			case 'ssl_peer_name':
			case 'ssl_verify_peer_name':
			case 'ssl_allow_self_signed':
			case 'ssl_capture_peer_cert':
			case 'ssl_capture_peer_cert_chain':
			case 'ssl_sni_enabled':
			case 'ssl_disable_compression':
			case 'ssl_peer_fingerprint':
			case 'ssl_security_level':
				if (false === is_bool($value)):
					throw new TypeError(sprintf('Argument 2 passed must be of the type boolean, %s given', gettype($value)));
				endif;
			break;
			
			default:
				if (false === is_string($value)):
					throw new TypeError(sprintf('Argument 2 passed must be of the type string, %s given', gettype($value)));
				endif;
			break;
		endswitch;
	}
}

?>