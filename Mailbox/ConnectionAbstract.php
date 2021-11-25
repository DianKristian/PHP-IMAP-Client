<?php
declare(strict_types=1);
namespace Mailbox;

use Mailbox\ConnectionInterface;

abstract class ConnectionAbstract implements ConnectionInterface {
	
	const CRLF = "\r\n";
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.1
	const COMMAND_ANY = 0;
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.2
	const COMMAND_NOT_AUTHENTICATED = 1;
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.3
	const COMMAND_AUTHENTICATED = 2;
	
	// https://datatracker.ietf.org/doc/html/rfc3501#section-6.4
	const COMMAND_SELECTED = 4;
	
	static protected $commands = [
		'CAPABILITY' 	=> self::COMMAND_ANY,
		'NOOP' 			=> self::COMMAND_ANY,
		'LOGOUT' 		=> self::COMMAND_ANY,
		'STARTTLS' 		=> self::COMMAND_NOT_AUTHENTICATED,
		'AUTHENTICATE' 	=> self::COMMAND_NOT_AUTHENTICATED,
		'LOGIN' 		=> self::COMMAND_NOT_AUTHENTICATED,
		'SELECT' 		=> self::COMMAND_AUTHENTICATED,
		'EXAMINE' 		=> self::COMMAND_AUTHENTICATED,
		'CREATE' 		=> self::COMMAND_AUTHENTICATED,
		'DELETE' 		=> self::COMMAND_AUTHENTICATED,
		'RENAME' 		=> self::COMMAND_AUTHENTICATED,
		'SUBSCRIBE' 	=> self::COMMAND_AUTHENTICATED,
		'UNSUBSCRIBE' 	=> self::COMMAND_AUTHENTICATED,
		'LIST' 			=> self::COMMAND_AUTHENTICATED,
		'LSUB' 			=> self::COMMAND_AUTHENTICATED,
		'STATUS' 		=> self::COMMAND_AUTHENTICATED,
		'APPEND' 		=> self::COMMAND_AUTHENTICATED,
		'CHECK' 		=> self::COMMAND_SELECTED,
		'CLOSE' 		=> self::COMMAND_SELECTED,
		'EXPUNGE' 		=> self::COMMAND_SELECTED,
		'SEARCH' 		=> self::COMMAND_SELECTED,
		'FETCH' 		=> self::COMMAND_SELECTED,
		'STORE' 		=> self::COMMAND_SELECTED,
		'COPY' 			=> self::COMMAND_SELECTED,
		'UID' 			=> self::COMMAND_SELECTED
	];
	
	const STATUS_MESSAGES = 1;
	const STATUS_RECENT = 2;
	const STATUS_UIDNEXT = 4;
	const STATUS_UIDVALIDITY = 8;
	const STATUS_UNSEEN = 16;
	const STATUS_ALL = 31;
	/*
	* @param string ("ALL" | "FULL" | "FAST" | fetch-att | "(" fetch-att *(SP fetch-att) ")")
	 * fetch-att:
	 * - ENVELOPE
	 * - FLAGS
	 * - INTERNALDATE
	 * - RFC822 [.HEADER | .SIZE | .TEXT]
	 * - BODY [STRUCTURE]
	 * - UID
	 * - BODY section [<number.nz-number>]
	 * - BODY.PEEK section [<number.nz-number>]
	 */
	const FETCH_UID = 1;
	const FETCH_BODY = 2;
	const FETCH_BODYSTRUCTURE = 4;
	const FETCH_ENVELOPE = 8;
	const FETCH_FLAGS = 16;
	const FETCH_INTERNALDATE = 32;
	const FETCH_RFC822_SIZE = 64;
	const FETCH_RFC822_HEADER = 128;
	const FETCH_RFC822_TEXT = 256;
	const FETCH_RFC822 = 512;
	const FETCH_TEXT_PLAIN = 1024;
	const FETCH_TEXT_HTML = 2048;
	const FETCH_ATTACHMENTS = 4096;
	const FETCH_FAST = self::FETCH_FLAGS|self::FETCH_INTERNALDATE|self::FETCH_RFC822_SIZE;
	const FETCH_ALL = self::FETCH_FAST|self::FETCH_ENVELOPE;
	const FETCH_FULL = self::FETCH_ALL|self::FETCH_BODY;
	
	const FLAG_NONE = 0;
	const FLAG_UID = 1;
	const FLAG_PEEK = 2;
	/*
	*/
	
	const FIELD_ENVELOPE_DATE = 'date';
	const FIELD_ENVELOPE_SUBJECT = 'subject';
	const FIELD_ENVELOPE_FROM = 'from';
	const FIELD_ENVELOPE_SENDER = 'sender';
	const FIELD_ENVELOPE_REPLYTO = 'reply_to';
	const FIELD_ENVELOPE_TO = 'to';
	const FIELD_ENVELOPE_CC = 'cc';
	const FIELD_ENVELOPE_BCC = 'bcc';
	const FIELD_ENVELOPE_INREPLYTO = 'in_reply_to';
	const FIELD_ENVELOPE_MESSAGEID = 'message_id';
	
	static private $envelopeFields = [
		self::FIELD_ENVELOPE_DATE,
		self::FIELD_ENVELOPE_SUBJECT,
		self::FIELD_ENVELOPE_FROM,
		self::FIELD_ENVELOPE_SENDER,
		self::FIELD_ENVELOPE_REPLYTO,
		self::FIELD_ENVELOPE_TO,
		self::FIELD_ENVELOPE_CC,
		self::FIELD_ENVELOPE_BCC,
		self::FIELD_ENVELOPE_INREPLYTO,
		self::FIELD_ENVELOPE_MESSAGEID
	];
	
	const FIELD_ADDR_NAME = 'name';
	const FIELD_ADDR_ADL = 'adl';
	const FIELD_ADDR_MAILBOX = 'mailbox';
	const FIELD_ADDR_HOST = 'host';
	
	static private $addressFields = [
		self::FIELD_ADDR_NAME,
		self::FIELD_ADDR_ADL,
		self::FIELD_ADDR_MAILBOX,
		self::FIELD_ADDR_HOST
	];
	
	protected $options;
	
	protected $connection;
	
	protected $capabilities = array();
	
	protected $isSelected = false;
	
	protected $selectedFolder;
	
	
	/**
	 * 
	 */
	public function __destruct() {
		$this->disconnect();
	}
	
	
	/**
	 * 
	 */
	public function disconnect():void {
		// When in selected state, execute CLOSE command to close current selected folder first.
		if ($this->isSelected):
			$this->command('CLOSE');
		endif;
		$this->command('LOGOUT');
	}
	
	
	/**
	 * @see: https://datatracker.ietf.org/doc/rfc5738/
	 */
	public function isUTF8Supported() {
		return isset($this->capabilities['UTF8']);
	}
	
	
	/**
	 * 
	 */
	public function toUTF8(string $string): string {
		return mb_convert_encoding($string, 'UTF-8', 'UTF7-IMAP');
	}
	
	
	/**
	 * Encode ISO-8859-1 or UTF-8 string into UTF7-IMAP
	 */
	public function toUTF7Imap(string $string):string {
		$fromEncoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1'], true);
		if (false === $fromEncoding):
			throw new \ValueError('Invalid encoding');
		endif;
		return mb_convert_encoding($string, 'UTF7-IMAP', $fromEncoding);
	}
	
	
	/**
	 * 
	 */
	abstract public function isConnected();
	
	
	/**
	 * 
	 */
	public function isAuthenticated():bool {
		return (isset($this->capabilities['AUTH']) === false);
	}
	
	
	/**
	 * 
	 */
	public function isSelected():bool {
		return $this->isSelected;
	}
	
	
	/**
	 * 
	**/
	public function getSelectedFolder():?string {
		return $this->selectedFolder;
	}
	
	
	/**
	 * Decode body structure format
	 * @param string
	 * @param int
	 * @return array
	 */
	protected function decode(array $characters):array {
		static $index = 0;
		$result = array();
		$length = count($characters);
		$text = '';
		$isQuoted = false;
		$isSection = false;
		if ($characters[$index] === '('):
			++$index;
		endif;
		while ($index < $length):
			$ord = ord($characters[$index]);
			$char = $characters[$index];
			++$index;
			if ($isQuoted):
				if ($char === '\\'):
					// Skip!!!
				elseif ($char === '"'):
					$isQuoted = false;
				else:
					$text .= $char;
				endif;
			elseif ($isSection):
				if ($char === ']'):
					$isSection = false;
				endif;
				$text .= $char;
			else:
				if ($char === '"'):
					$isQuoted = true;
				elseif ($char === '['):
					$isSection = true;
					$text .= $char;
				elseif ($char === '('):
					--$index;
					$result[] = $this->decode($characters);
				elseif (in_array($char, ['{', "\r", "\n"])):
				elseif (in_array($char, [' ', '}', ')'])):
					if ($text !== ''):
						if ($text === 'NIL'):
							$text = null;
						elseif (preg_match('@^[0-9]+$@', $text)):
							$text = (int)$text;
						else:
							$text = trim($text, '"');
						endif;
						$result[] = $text;
						$text = '';
					endif;
					if ($char === ')'):
						break;
					endif;
				else:
					$text .= $char;
				endif;
			endif;
			//sleep(1);
		endwhile;
		if ($index === $length):
			if ($text !== ''):
				$result[] = $text;
			endif;
			$index = 0;
		endif;
		return $result;
	}
	
	
	
	/**
	 * The NOOP command always succeeds.  It does nothing.
	 * Since any command can return a status update as untagged data, the NOOP command can be used as a periodic poll for new messages or 
	 * message status updates during a period of inactivity (this is the preferred method to do this).
	 * The NOOP command can also be used to reset any inactivity autologout timer on the server.
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.2
	 */
	public function noop() {
		return $this->command('NOOP');
	}
	
	
	
	/**
	 * Informs the server that the client is done with the connection.
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.3
	 */
	public function logout(): bool {
		return $this->command('LOGOUT');
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
		
		$username = $this->options['username'];
		$password = $this->options['password'];
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
		return $result;
	}
	
	
	/**
	 * 
	 */
	public function authenticateLogin(string $username, string $password):bool {
		$commands = array();
		$commands[] = 'AUTHENTICATE LOGIN';
		$commands[] = base64_encode($this->options['username']);
		$commands[] = base64_encode($this->options['password']);
		foreach ($commands as $command):
			$result = $this->command($command, $commandTag);
			if (false === $result):
				break;
			endif;
		endforeach;
		return $result;
	}
	
	
	/**
	 * 
	 */
	public function authenticatePlain(string $username, string $password):bool {
		$commands = array();
		$commands[] = 'AUTHENTICATE LOGIN';
		$commands[] = base64_encode($this->options['username']);
		$commands[] = base64_encode($this->options['password']);
		foreach ($commands as $command):
			$result = $this->command($command, $commandTag);
			if (false === $result):
				break;
			endif;
		endforeach;
		return $result;
	}
	
	
	/**
	 * Identifies the client to the server and carries the plaintext password authenticating this user.
	 * @param string
	 * @param string
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.3
	 */
	public function login(string $username, string $password):bool {
		$command = sprintf('LOGIN %s %s', $username, $password);
		return $this->command($command);
	}
	
	
	/**
	 * Selects a mailbox so that messages in the mailbox can be accessed.
	 * @param string
	 * @return object Mailbox
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.1
	 */
	public function select(string $mailbox, bool $readonly = false) {
		return new \Mailbox\Mailbox($this, $mailbox, $readonly);
	}
	
	
	/**
	 * Creates a mailbox with the given name.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.3
	 */
	public function create(string $mailbox): bool {
		return $this->command(sprintf('CREATE "%s"', $mailbox));
	}
	
	
	/**
	 * Permanently removes the mailbox with the given name.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.4
	 */
	public function delete(string $mailbox): bool {
		return $this->command(sprintf('DELETE "%s"', $mailbox));
	}
	
	
	/**
	 * Changes the name of a mailbox.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.5
	 */
	public function rename(string $oldMailbox, string $newMailbox):bool {
		return $this->command(sprintf('RENAME "%s" "%s"', $oldMailbox, $newMailbox));
	}
	
	
	/**
	 * Adds the specified mailbox name to the server's set of "active" or "subscribed" mailboxes as returned by the LSUB command.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.6
	 */
	public function subscribe(string $mailbox):bool {
		return $this->command(sprintf('SUBSCRIBE "%s"', $mailbox));
	}
	
	
	/**
	 * Removes the specified mailbox name from the server's set of "active" or "subscribed" mailboxes as returned by the LSUB command.
	 * @param string
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.7
	 */
	public function unsubscribe(string $mailbox):bool {
		return $this->command(sprintf('UNSUBSCRIBE "%s"', $mailbox));
	}
	
	
	/**
	 * Returns a subset of names from the complete set of all names available to the client.
	 * @param string mailbox name with possible wildcards
	 * @param string reference name
	 * @return object ArrayObject | false
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.8
	 */
	public function list(string $reference = '', string $mailbox = '*') {
		$command = sprintf('LIST "%s" "%s"', $reference, $mailbox);
		if (false === $this->command($command)):
			throw new \Error($this->getErrorText());
		endif;
		$result = $this->getResults();
		return isset($result['LIST']) ? $result['LIST'] : [];
	}
	
	
	/**
	 * Returns a subset of names from the set of names that the user has declared as being "active" or "subscribed".
	 * @param string reference name
	 * @param string mailbox name with possible wildcards
	 * @return object ArrayObject | false
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.9
	 */
	public function lsub(string $reference = '', string $mailbox = '*') {
		$command = sprintf('LSUB "%s" "%s"', $reference, $mailbox);
		if (false === $this->command($command)):
			throw new \Error($this->getErrorText());
		endif;
		$result = $this->getResults();
		return isset($result['LSUB']) ? $result['LSUB'] : [];
	}
	
	
	/**
	 * Requests the status of the indicated mailbox.
	 * @param string mailbox name
	 * @param int status flags:
	 * - self::STATUS_MESSAGES The number of messages in the mailbox.
	 * - self::STATUS_RECENT The number of messages with the \Recent flag set.
	 * - self::STATUS_UIDNEXT The next unique identifier value of the mailbox.
	 * - self::STATUS_UIDVALIDITY The unique identifier validity value of the mailbox.
	 * - self::STATUS_UNSEEN The number of messages which do not have the \Seen flag set.
	 * - self::STATUS_ALL All statuses.
	 * @return object ArrayObject
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.10
	 */
	public function status(string $mailbox, int $flags = self::STATUS_ALL):array {
		$status = [];
		if ($flags & self::STATUS_MESSAGES):
			$status[] = 'MESSAGES';
		endif;
		if ($flags & self::STATUS_RECENT):
			$status[] = 'RECENT';
		endif;
		if ($flags & self::STATUS_UIDNEXT):
			$status[] = 'UIDNEXT';
		endif;
		if ($flags & self::STATUS_UIDVALIDITY):
			$status[] = 'UIDVALIDITY';
		endif;
		if ($flags & self::STATUS_UNSEEN):
			$status[] = 'UNSEEN';
		endif;
		
		if (empty($status)):
			throw new \ValueError('Parameter 2 required');
		endif;
		
		$command = sprintf('STATUS "%s" (%s)', $mailbox, implode(' ', $status));
		
		if (false === $this->command($command)):
			throw new \Error($this->responseMessage);
		endif;
		
		$results = $this->getResults();		
		return isset($results['STATUS']) ? $results['STATUS'] : [];
	}
	
	
	/**
	 * Appends the literal argument as a new message to the end of the specified destination mailbox.
	 * This argument SHOULD be in the format of an [RFC-2822] message.  8-bit characters are permitted 
	 * in the message.  A server implementation that is unable to preserve 8-bit data properly MUST be 
	 * able to reversibly convert 8-bit APPEND data to 7-bit using a [MIME-IMB] content transfer encoding.
	 * @param string
	 * @param array 
	 * @param array
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.11
	public function append(string $mailbox, array $message, array $flags = []):bool {
	}
	 */
	
	
	/**
	 * Parse CAPABILITY response that occurs as a result of a CAPABILITY command.
	 * @param string
	 * @return object ArrayObject
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.1
	 */
	protected function parseCapability(string $string):\ArrayObject {
		$capabilities = [];
		$data = preg_split('/[ ]+/u', trim($string));
		foreach ($data as $item):
			if (false === strpos($item, '=')):
				$capabilities[$item] = true;
				continue;
			endif;
			@list($key, $value) = explode( '=', $item);
			if (false === (isset($capabilities[$key]) && is_array($capabilities[$key]))):
				$capabilities[$key] = [];
			endif;
			$capabilities[$key][] = $value;
		endforeach;
		return new \ArrayObject($capabilities);
	}
	
	
	/**
	 * Parse LIST and LSUB response that occurs as a result of a LIST or LSUB command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.2
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.3
	 */
	protected function parseList(string $string):array {
		$regexp = '@^\(([^\)]*)\)[ ]+("(?:[^"]*)"|(?:[^\s]+))[ ]+("(?:[^"]*)"|(?:[^\s]+))$@u';
		if (false === (bool)preg_match($regexp, trim($string), $match)):
			throw new \ParseError('Parse LIST response failed');
		endif;
		array_shift($match);
		$match = array_map('trim', $match);
		return [
			'attributes' 	=> empty($match[0]) ? [] : preg_split('@[ ]+@u', $match[0]),
			'delimiter' 	=> $match[1],
			'name' 			=> $this->toUTF8($match[2])
		];
	}
	
	
	/**
	 * Parse STATUS response that occurs as a result of a STATUS command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.4
	 */
	protected function parseStatus(string $string):array {
		if (false === (bool)preg_match('/\(([^\)]+)\)/u', $string, $match)):
			throw new \ParseError('Parse STATUS response failed');
		endif;
		if (false === (bool)preg_match_all('/((MESSAGES|RECENT|UIDNEXT|UIDVALIDITY|UNSEEN)\s+([0-9]+))/', $match[1], $matches, PREG_PATTERN_ORDER)):
			throw new \ParseError('Parse STATUS response failed');
		endif;
		return array_combine($matches[2], array_map('intval', $matches[3]));
	}
	
	
	/**
	 * Parse SEARCH response that occurs as a result of a SEARCH or UID SEARCH command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.5
	 */
	protected function parseSearch(string $string):array {
		if (false === (bool)preg_match_all('@([0-9]+)@u', $string, $match)):
			throw new \ParseError('Parse SEARCH response failed');
		endif;
		return array_map('intval', $match[1]);
	}
	
	
	/**
	 * Parse FLAGS response that occurs as a result of a SELECT or EXAMINE command.
	 * @param string
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-7.2.6
	 */
	protected function parseFlags(string $string):array {
		if (false === (bool)preg_match('@\(([^\)]*)\)@u', $string, $match)):
			throw new \ParseError('Parse FLAGS response failed');
		endif;
		$match[1] = trim($match[1]);
		return empty($match[1]) ? [] : preg_split('@[ ]+@', $match[1]);
	}
	
	
	/**
	 * 
	 */
	protected function parseFetchResponse(string $string, \ArrayObject $lines):array {
		static $lineIndex = 0;
		
		$response = $this->decode(str_split($string));
		$response = array_chunk($response, 2);
		
		$results = [];
		// sequenceNumber
		// uid
		// internalDate 
		// size
		// flags
		// envelope
		// structure
		// header
		// text
		foreach ($response as $item):
			$name = $item[0];
			switch ($name):
				case 'UID': // int
				case 'RFC822.SIZE': // int OK
				case 'INTERNALDATE': // string date
				case 'FLAGS': // numeric array OK
					$results[$name] = $item[1];
					break;
					
				case 'ENVELOPE': // OK
					// @see: https://datatracker.ietf.org/doc/html/rfc3501#section-9
					$results[$name] = [];
					foreach ($item[1] as $subkey => $subval):
						if (isset(self::$envelopeFields[$subkey])):
							$subkey = self::$envelopeFields[$subkey];
						endif;
						switch ($subkey):
							case self::FIELD_ENVELOPE_FROM:
							case self::FIELD_ENVELOPE_SENDER:
							case self::FIELD_ENVELOPE_REPLYTO:
							case self::FIELD_ENVELOPE_TO:
							case self::FIELD_ENVELOPE_CC:
							case self::FIELD_ENVELOPE_BCC:
							case self::FIELD_ENVELOPE_INREPLYTO:
								if ($subval === null):
									$subval = [];
								elseif (false === is_array($subval)):
									throw new \ParseError(sprintf('Parse %s response failed. Maybe malformed response', $subkey));
								else:
									$subval = array_map([$this, 'buildAddress'], $subval);
								endif;
								break;
						endswitch;
						$results[$name][$subkey] = $subval;
					endforeach;
					break;
					
				case 'BODY':
				case 'BODYSTRUCTURE':
					// https://datatracker.ietf.org/doc/html/rfc2045#section-5.1
					$results[$name] = $this->buildBodyStructure($item[1]);
					break;
					
				case (bool)preg_match('@^(BODY\[[^\]]*\](?:\<[^\>]+\>)?|RFC822(?:\.(?:TEXT|HEADER))?)$@u', $name, $match):
					// imap response sucks!!!
					$length = $item[1];
					$results[$name] = '';
					$lineTotal = $lines->count();
					while ($lineIndex < $lineTotal):
						$chars = str_split($lines[$lineIndex]);
						++$lineIndex;
						$charLength = count($chars);
						$remainLine = '';
						for ($i = 0; $i < $charLength; ++$i):
							if (mb_strlen($results[$name]) > $length):
								$remainLine .= $chars[$i];
							else:
								$results[$name] .= $chars[$i];
							endif;
						endfor;
						if ($remainLine !== ''):
							$results = array_merge($results, $this->parseFetchResponse(trim($remainLine), $lines));
						endif;
					endwhile;
					if ($lineIndex === $lineTotal):
						if (substr($results[$name], -1) === ')'):
							$results[$name] = substr($results[$name], 0, -1);
						endif;
						$lineIndex = 0;
						break;
					endif;
					break;
					
				default:
					if (false === empty($results)):
						throw new \Error('Something wrong happen');
					endif;
					break 2;
					
			endswitch;
			
		endforeach;
		return $results;
	}
	
	
	/**
	 * 
	 */
	protected function buildBodyStructure($item):array {
		$basicFields = ['type', 'subtype', 'parameter', 'id', 'description', 'encoding', 'size'];
		$extensionFields = ['md5', 'disposition', 'language', 'location'];
		$results = [];
		$length = count($item);
		$hasChildrenNodes = is_array($item[0]);
		$key = null;
		$fieldIndex = 1;
		for ($i = 0; $i < $length; ++$i):
			if ($hasChildrenNodes):
				if (is_array($item[$i]) && $key === null):
					$results['parts'][] = $this->buildBodyStructure($item[$i]);
					continue;
				endif;
				
				if (false === isset($results['type'])):
					$results['type'] = 'MULTIPART';
				endif;
				if (false === isset($basicFields[$fieldIndex])):
					$basicFields = array_merge($basicFields, $extensionFields);
				endif;
				$key = $basicFields[$fieldIndex];
				++$fieldIndex;
			else:
				if (false === isset($basicFields[$i])):
					if (in_array($results['type'], ['TEXT'])):
						$basicFields[] = 'lines';
					endif;
					$basicFields = array_merge($basicFields, $extensionFields);
				endif;
				$key = $basicFields[$i];
			endif;
			
			
			if ($key === 'parameter' && is_array($item[$i])):
				$subkey = null;
				$parameterLength = count($item[$i]);
				for ($parameterIndex = 0; $parameterIndex < $parameterLength; ++$parameterIndex):
					if ($parameterIndex % 2 === 0):
						$subkey = $item[$i][$parameterIndex];
						continue;
					endif;
					if ($subkey !== null):
						$results[$key][$subkey] = $item[$i][$parameterIndex];
						$subkey = null;
					endif;
				endfor;
			elseif ($key === 'disposition'):
				if (is_array($item[$i])):
					$results[$key] = array_shift($item[$i]);
					$results['disposition_parameter'] = [];
					$dispositionLength = count($item[$i][0]);
					for ($dispositionIndex = 0; $dispositionIndex < $dispositionLength; ++$dispositionIndex):
						if ($dispositionIndex % 2 === 0):
							$subkey = $item[$i][0][$dispositionIndex];
							continue;
						endif;
						if ($subkey !== null):
							$results['disposition_parameter'][$subkey] = $item[$i][0][$dispositionIndex];
							$subkey = null;
						endif;
					endfor;
				else:
					$results[$key] = $item[$i];
					$results['disposition_parameter'] = [];
				endif;
			else:
				$results[$key] = $item[$i];
			endif;
		endfor;
		return $results;
	}
	
	
	/**
	 * Callback for build email address info
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-9
	 */
	protected function buildAddress(array $item):array {
		if (count($item) === 4):
			$item = array_combine(self::$addressFields, $item);
		endif;
		return $item;
	}
}

?>