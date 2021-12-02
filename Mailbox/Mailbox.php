<?php

namespace Mailbox;

//require_once __DIR__ . DIRECTORY_SEPARATOR . 'Config.php';
//require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConnectionSocket.php';

//use Config;


class Mailbox {
	const FLAG_NONE = 0;
	const FLAG_ANSWERED = 1;
	const FLAG_FLAGGED = 2;
	const FLAG_DELETED = 4;
	const FLAG_SEEN = 8;
	const FLAG_DRAFT = 16;
	const FLAG_RECENT = 32;
	
	protected $status = [];
	protected $folder;
	protected $readonly = false;
	protected $list;
	protected $uids = [];
	protected $messages = [];
	protected $connection;
	
	public function __construct(ConnectionAbstract &$connection, string $mailbox, bool $readonly = false) {
		$this->folder = $mailbox;
		$this->readonly = $readonly;
		$this->connection = &$connection;
		$this->select();
	}
	
	
	/**
	 * @return bool
	 */
	protected function select():bool {
		if ($this->folder === null):
			return false;
		endif;
		if ($this->connection->getSelectedFolder() === $this->folder):
			return true;
		endif;
		$folder = $this->folder;
		
		$isUTF8Supported = false;
		if (isset($this->capabilities['UTF8'])):
			if (in_array('ACCEPT', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $this->capabilities['UTF8'])):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$folder = $this->connection->toUTF7Imap($folder);
		endif;
		$command = sprintf('%s "%s"', $this->readonly ? 'EXAMINE' : 'SELECT', $folder);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->errorText);
		endif;
		$this->status = array_merge($this->status, $this->connection->results);
		return true;
	}
	
	
	/**
	 * 
	**/
	public function getFolder() {
		return $this->folder;
	}
	
	
	/**
	 * To close the Connection (if the connection is not disconnected after every request)
	 */
	public function disconnect():void {
		$this->connection->disconnect();
	}
	
	
	/**
	 * if the connection is not always checked and not disconnected after each request)
	 */
	public function isConnected():bool {
		return $this->connection->isConnected();
	}
	
	
	/**
	 * Requests a checkpoint of the currently selected mailbox.
	 * @return boolean
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.1
	 */
	public function check():bool {
		$this->select();
		return $this->connection->command('CHECK');
	}
	
	
	/**
	 * Permanently removes all messages that have the \Deleted flag set from the currently selected mailbox.
	 * @return object array list of deleted messages
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.3
	 * @command-select
	 */
	public function expunge():bool {
		if (false === $this->connection->command('EXPUNGE')):
			return false;
		endif;
		if (isset($this->connection->results['EXPUNGE']) && is_array($this->connection->results['EXPUNGE'])):
			$count = count($this->connection->results['EXPUNGE']);
			for ($i = 0; $i < $count; ++$i):
				$sequence = $this->connection->results['EXPUNGE'][$i];
				if (isset($this->uids[$sequence])):
					$uid = $this->uids[$sequence];
					unset($this->uids[$sequence]);
					unset($this->messages[$uid]);
				endif;
			endfor;
		endif;
		return true;
	}
	
	
	/**
	 * Searches the mailbox for messages that match the given searching criteria parameters.
	 * @command-select
	 */
	public function search(array $criteria = [], bool $returnUid = false) {
		if (empty($criteria)):
			$string = 'ALL';
		else:
			$searches = [];
			foreach ($criteria as $key => $value):
				if (false === is_string($key)):
					continue;
				endif;
				switch (strtoupper($key)):
					case 'FLAGS':
						if (false === is_int($value)):
							break;
						endif;
						if ($value & self::FLAG_ANSWERED):
							$searches[] = 'ANSWERED';
						elseif ($value & self::FLAG_FLAGGED):
							$searches[] = 'FLAGGED';
						elseif ($value & self::FLAG_DELETED):
							$searches[] = 'DELETED';
						elseif ($value & self::FLAG_SEEN):
							$searches[] = 'SEEN';
						elseif ($value & self::FLAG_DRAFT):
							$searches[] = 'DRAFT';
						elseif ($value & self::FLAG_RECENT):
							$searches[] = 'RECENT';
						endif;
					break;
					
					// string
					case 'BCC':
					case 'BODY':
					case 'CC':
					case 'FROM':
					case 'HEADER':
					case 'NOT':
					case 'OR':
					case 'SUBJECT':
					case 'TEXT':
					case 'TO':
					break;
					
					// date
					case 'BEFORE':
					case 'ON':
					case 'SENTBEFORE':
					case 'SENTON':
					case 'SENTSINCE':
					case 'SINCE':
					
					// Flag
					case 'KEYWORD':
					
					// Octet
					case 'LARGER':
					case 'SMALLER':
					
					case 'UID':
				endswitch;
			endforeach;
			$string = implode(' ', $searches);
		endif;
		$command = sprintf('%sSEARCH %s', $returnUid ? 'UID ' : '', $string);
		if (false === $this->connection->command($command)):
			return false;
		endif;
		return isset($this->connection->results['SEARCH']) ? $this->connection->results['SEARCH'] : [];
	}
	
	
	/**
	 * Retrieves INTERNALDATE data associated with a message in the selected mailbox.
	 * @params int
	 * @return string
	 */
	public function getInternalDate(int $uid) {
		$this->select();
		$field = 'INTERNALDATE';
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->errorText);
		endif;
		$sequenceNumber = array_search($uid, $this->uids);
		if (false === $sequenceNumber):
			return false;
		endif;
		if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
			throw new \Error('Oops! Something wrong with the results');
		endif;
		var_dump($this->connection->results['FETCH']);
		$result = $this->connection->results;
		if (empty($result)):
			return false;
		endif;
		$result = array_shift($result);
		return $result[$field];
	}
	
	
	/**
	 * Retrieves RFC822.SIZE data associated with a message in the selected mailbox.
	 * @params int
	 * @return int
	 */
	public function getSize(int $uid):int {
		$this->select();
		$field = 'RFC822.SIZE';
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->errorText);
		endif;
		$result = $this->connection->results;
		if (empty($result)):
			return false;
		endif;
		$result = array_shift($result);
		return $result[$field];
	}
	
	
	// (output all folders (example: INBOX, INBOX/Folder1 SPAM, ...)
	public function getFolders() {
		$this->select();
		$list = $this->connection->list($this->connection->toUTF7Imap($this->folder), '*');
		$folders = [];
		$count = count($list);
		for ($i = 0; $i < $count; ++$i):
			$folders[] = $list[$i]['name'];
		endfor;
		return $folders;
	}
	
	
	// (Check if e.g. INBOX/Folder1 exists)
	public function checkFolder(string $folder) {
		$this->select();
		$folder = $this->folder . '/' . $folder;
		$list = $this->getFolders();
		return in_array($folder, $list);
	}
	
	
	// (Creates a folder e.g. INBOX/Folder1))
	public function createFolder(string $folder) {
		return $this->connection->command(sprintf(
			'CREATE "%s"', 
			//$this->connection->toUTF7Imap($this->folder), 
			$this->connection->toUTF7Imap($folder)
		));
	}
	
	// (Count the messages in a folder)
	public function countMessages() {
		return $this->status['EXISTS'];
	}
	
	/**
	 * Returns all message UIDs;
	**/
	public function getMessages():array {
		$this->select();
		if (false === $this->connection->command('UID SEARCH ALL')):
			throw new \Error($this->connection->errorText);
		endif;
		$results = [];
		if (isset($this->connection->results['SEARCH']) && is_array($this->connection->results['SEARCH'])):
			$result = $this->connection->results['SEARCH'];
		endif;
		return $result;
	}
	
	
	/**
	 * Copies the specified message(s) to the end of the specified destination mailbox.
	 * The flags and internal date of the message(s) SHOULD be preserved, and the Recent flag SHOULD 
	 * be set, in the copy.
	 * @param int|string sequence set (ex. 2:4)
	 * @param string mailbox name
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.7
	 */
	public function copyMessage(int $uid, string $newFolder):bool {
		$this->select();
		$isUTF8Supported = false;
		$capabilities = $this->connection->getCapability('UTF8');
		if (is_array($capabilities)):
			if (in_array('ACCEPT', $capabilities)):
				$isUTF8Supported = true;
			elseif (in_array('ALL', $capabilities)):
				$isUTF8Supported = true;
			elseif (in_array('ONLY', $capabilities)):
				$isUTF8Supported = true;
			endif;
		endif;
		if (false === $isUTF8Supported):
			$newFolder = $this->connection->toUTF7Imap($newFolder);
		endif;
		return $this->connection->command(sprintf('UID COPY %d "%s"', $uid, $newFolder));
	}
	
	
	/**
	 * (By UID or message ID)
	 */
	public function moveMessage(int $uid, string $newFolder) {
		if (false === $this->copyMessage($uid, $newFolder)):
			return false;
		endif;
		return $this->deleteMessage($uid);
	}
	
	
	/**
	 * (By UID or message ID)
	 */
	public function deleteMessage(int $uid) {
		if (false === $this->addFlags($uid, self::FLAG_DELETED)):
			return false;
		endif;
		if (false === $this->expunge()):
			$this->removeFlags($uid, self::FLAG_DELETED);
			return false;
		endif;
		return true;
	}
	
	
	/**
	 * Get unique identification (UID) for given message in selected folder
	 * @param int sequence number of message
	 * @return int if sequence number not found method will return 0
	 */
	public function getUid(int $sequenceNumber):int {
		if (false === isset($this->uids[$sequenceNumber])):
			$this->select();
			$command = sprintf('FETCH %d UID', $sequenceNumber);
			if (false === $this->connection->command($command)):
				return false;
			endif;
			if (false === isset($this->connection->results['FETCH'][$sequenceNumber]['UID'])):
				throw new \Error('Oops! Something wrong with the results');
			endif;
			$this->uids[$sequenceNumber] = $this->connection->results['FETCH'][$sequenceNumber]['UID'];
		endif;
		return $this->uids[$sequenceNumber];
	}
	
	
	/**
	 * Get all structure information for given message in selected folder
	 * @param int
	 * @return array with attributes listed below
	 * - parts: array available when type multipart
	 * - type: string primary body type
	 * - subtype: string|null MIME subtype
	 * - parameter: array with key-value pairs
	 * - id: string|null identification string
	 * - description: string|null content description
	 * - encoding: string body transfer encoding
	 * - size: int number of bytes
	 * - lines: int number of lines, available for type text
	 */
	public function getStructure(int $uid, bool $extensible = false) {
		if (false === isset($this->messages[$uid]['structure'])):
			$this->select();
			$field = $extensible ? 'BODYSTRUCTURE' : 'BODY';
			$command = sprintf('UID FETCH %d %s', $uid, $field);
			if (false === $this->connection->command($command)):
				return false;
			endif;
			if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
				throw new \Error('Oops! Something wrong with the results');
			endif;
			$results = array_shift($this->connection->results['FETCH']);
			if (false === (isset($results[$field]) && is_object($results[$field]))):
				throw new \Error('Oops! Something wrong with the results');
			endif;
			if ($field === 'BODY'):
				return $results[$field];
			endif;
			$this->messages[$uid]['structure'] = $results[$field];
		endif;
		return $this->messages[$uid]['structure'];
	}
	
	
	/**
	 * Get all envelope information for given message in selected folder
	 * @param int message UID
	 * @return object with fields:
	 *  date: string
	 *  subject: string
	 *  from: array "mail addresses"
	 *  sender: array "mail addresses"
	 *  reply_to: array "mail addresses"
	 *  to: array "mail addresses"
	 *  cc: array "mail addresses"
	 *  bcc: array "mail addresses"
	 *  in_reply_to: array "mail addresses"
	 *  message_id: string| null
	 * mail addresses values can empty array or more than one addresses, fields:
	 *  name string|null
	 *  adl string|null
	 *  mailbox string
	 *  host string
	 */
	public function getEnvelope(int $uid):\stdClass {
		if (false === isset($this->messages[$uid]['envelope'])):
			$this->select();
			$command = sprintf('UID FETCH %d ENVELOPE', $uid);
			if (false === $this->connection->command($command)):
				return false;
			endif;
			if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
				throw new \Error('Oops! Something wrong with the results');
			endif;
			$results = array_shift($this->connection->results['FETCH']);
			if (false === (isset($results['ENVELOPE']) && is_object($results['ENVELOPE']))):
				throw new \Error('Oops! Something wrong with the results');
			endif;
			$this->messages[$uid]['envelope'] = $results['ENVELOPE'];
		endif;
		return $this->messages[$uid]['envelope'];
	}
	
	
	/**
	 * Return reply_to field email addresses information  for given message in selected folder
	 * @param int message UID
	 * @return array see: getEnvelope method
	 */
	public function getReplyTo(int $uid):array {
		$envelope = $this->getEnvelope($uid);
		return $envelope->reply_to;
	}
	
	
	/**
	 * Return from field of email addresses information  for given message in selected folder
	 * @param int message UID
	 * @return array see: getEnvelope method
	 */
	public function getFrom(int $uid):array {
		$envelope = $this->getEnvelope($uid);
		return $envelope->from;
	}
	
	
	/**
	 * Return subject field of email addresses information  for given message in selected folder
	 * @param int message UID
	 * @return string see: getEnvelope method
	 */
	public function getSubject(int $uid) {
		$envelope = $this->getEnvelope($uid);
		return $envelope->subject;
	}
	
	
	/**
	 * 
	 */
	public function getBody(int $uid, string $section = ''):string {
		$field = sprintf('BODY[%s]', $section);
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			return '';
		endif;
		if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
			throw new \Error('Oops! Something wrong with the results');
		endif;
		$results = array_shift($this->connection->results['FETCH']);
		if (false === (isset($results[$field]) && is_string($results[$field]))):
			throw new \Error('Oops! Something wrong with the results');
		endif;
		return trim($results[$field]);
	}
	
	
	/**
	 * Output only the html Body if exist
	**/
	public function getHtmlBody(int $uid):string {
		$structure = $this->getStructure($uid, true);
		if (empty($structure)):
			return '';
		endif;
		$structure = $this->getStructureSection($structure, 'subtype', 'html');
		if (empty($structure)):
			return '';
		endif;
		$structure = array_shift($structure);
		if (false === isset($structure->section)):
			$structure->section = 1;
		endif;
		$result = $this->getBody($uid, $structure->section);
		if (false === empty($structure->encoding)):
			$structure->encoding = strtolower($structure->encoding);
			if ($structure->encoding === 'base64'):
				$result = base64_decode($result);
			elseif ($structure->encoding === 'quoted-printable'):
				$result = quoted_printable_decode($result);
			endif;
		endif;
		if (false === empty($structure->parameter) && is_array($structure->parameter)):
			$count = count($structure->parameter);
			for ($i = 0; $i < $count; ++$i):
				if (strtolower($structure->parameter[$i]->attribute) !== 'charset'):
					continue;
				endif;
				if (strtolower($structure->parameter[$i]->value) === 'utf-8'):
					continue;
				endif;
				$result = mb_convert_encoding($result, 'UTF-8', $structure->parameter[$i]->value);
				break;
			endfor;
		endif;
		return $result;
	}
	
	/**
	 * Output only the text body if exist
	**/
	public function getTextBody(int $uid, bool $peek = false):string {
		$structure = $this->getStructure($uid, true);
		if (empty($structure)):
			return '';
		endif;
		$structure = $this->getStructureSection($structure, 'subtype', 'plain');
		if (empty($structure)):
			return '';
		endif;
		$structure = array_shift($structure);
		if (false === isset($structure->section)):
			$structure->section = 1;
		endif;
		$result = $this->getBody($uid, $structure->section);
		if (false === empty($structure->encoding)):
			$structure->encoding = strtolower($structure->encoding);
			if ($structure->encoding === 'base64'):
				$result = base64_decode($result);
			elseif ($structure->encoding === 'quoted-printable'):
				$result = quoted_printable_decode($result);
			endif;
		endif;
		if (false === empty($structure->parameter) && is_array($structure->parameter)):
			$count = count($structure->parameter);
			for ($i = 0; $i < $count; ++$i):
				if (strtolower($structure->parameter[$i]->attribute) !== 'charset'):
					continue;
				endif;
				if (strtolower($structure->parameter[$i]->value) === 'utf-8'):
					continue;
				endif;
				$result = mb_convert_encoding($result, 'UTF-8', $structure->parameter[$i]->value);
				break;
			endfor;
		endif;
		return $result;
	}
	
	
	/**
	 * (Inline and attached - Name, Extension, Name without extension, size, MimeType)
	 */
	public function getAttachments(int $uid):array {
		$structure = $this->getStructure($uid, true);
		if (false === empty($structure)):
			$structure = $this->getStructureSection($structure, 'disposition', 'attachment');
		endif;
		return $structure;
	}
	
	
	/**
	 * If this informations not in getAttachmets - To get information to save attachments with file_out_contents)
	 */
	public function getAttachmentData(int $uid, string $section) {
		return $this->getBody($uid, $section);
	}
	
	
	/**
	 * Retrieves FLAGS data associated with a message in the selected mailbox.
	 * @param int
	 * @return array
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.6
	 */
	public function getFlags(int $uid):array {
		if (false === isset($this->messages[$uid]['flags'])):
			$this->select();
			$command = sprintf('UID FETCH %d FLAGS', $uid);
			if (false === $this->connection->command($command)):
				return false;
			endif;
			if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
				throw new \Error('Oops! Something wrong with the results');
			endif;
			$results = array_shift($this->connection->results['FETCH']);
			$this->messages[$uid]['flags'] = [];
			if (isset($results['FLAGS']) && is_array($results['FLAGS'])):
				$this->messages[$uid]['flags'] = $results['FLAGS'];
			endif;
		endif;
		return $this->messages[$uid]['flags'];
	}
	
	
	/**
	 * Add FLAGS data associated with a message in the selected mailbox.
	 * @param int
	 * @param int
	 * - self::FLAG_NONE
	 * - self::FLAG_ANSWERED
	 * - self::FLAG_FLAGGED
	 * - self::FLAG_DELETED
	 * - self::FLAG_SEEN
	 * - self::FLAG_DRAFT
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.6
	 */
	public function addFlags(int $uid, int $flags):bool {
		$values = [];
		if ($flags & self::FLAG_ANSWERED):
			$values[] = '\\Answered';
		endif;
		if ($flags & self::FLAG_FLAGGED):
			$values[] = '\\Flagged';
		endif;
		if ($flags & self::FLAG_DELETED):
			$values[] = '\\Deleted';
		endif;
		if ($flags & self::FLAG_SEEN):
			$values[] = '\\Seen';
		endif;
		if ($flags & self::FLAG_DRAFT):
			$values[] = '\\Draft';
		endif;
		if (empty($values)):
			return false;
		endif;
		$this->select();
		$command = sprintf('UID STORE %d +FLAGS (%s)', $uid, implode(' ', $values));
		if (false === $this->connection->command($command)):
			return false;
		endif;
		if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
			return true;
		endif;
		$results = array_shift($this->connection->results['FETCH']);
		if (false === empty($results['FLAGS']) && is_array($results['FLAGS'])):
			if (empty($this->messages[$uid]['flags'])):
				if (false === isset($this->messages[$uid])):
					$this->messages[$uid] = [];
				endif;
				$this->messages[$uid]['flags'] = $results['FLAGS'];
			else:
				$count = count($results['FLAGS']);
				for ($i = 0; $i < $count; ++$i):
					if (in_array($results['FLAGS'][$i], $this->messages[$uid]['flags'])):
						continue;
					endif;
					$this->messages[$uid]['flags'][] = $results['FLAGS'][$i];
				endfor;
			endif;
		endif;
		return true;
	}
	
	
	/**
	 * Remove flags FLAGS data associated with a message in the selected mailbox.
	 * @param int
	 * @param int
	 * - self::FLAG_NONE
	 * - self::FLAG_ANSWERED
	 * - self::FLAG_FLAGGED
	 * - self::FLAG_DELETED
	 * - self::FLAG_SEEN
	 * - self::FLAG_DRAFT
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.6
	 */
	public function removeFlags(int $uid, int $flags):bool {
		$values = [];
		if ($flags & self::FLAG_ANSWERED):
			$values[] = '\\Answered';
		endif;
		if ($flags & self::FLAG_FLAGGED):
			$values[] = '\\Flagged';
		endif;
		if ($flags & self::FLAG_DELETED):
			$values[] = '\\Deleted';
		endif;
		if ($flags & self::FLAG_SEEN):
			$values[] = '\\Seen';
		endif;
		if ($flags & self::FLAG_DRAFT):
			$values[] = '\\Draft';
		endif;
		if (empty($values)):
			return false;
		endif;
		$this->select();
		$command = sprintf('UID STORE %d -FLAGS (%s)', $uid, implode(' ', $values));
		if (false === $this->connection->command($command)):
			return false;
		endif;
		if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
			return true;
		endif;
		$results = array_shift($this->connection->results['FETCH']);
		if (false === empty($results['FLAGS']) && is_array($results['FLAGS'])):
			if (empty($this->messages[$uid]['flags'])):
				if (false === isset($this->messages[$uid])):
					$this->messages[$uid] = [];
				endif;
				$this->messages[$uid]['flags'] = $results['FLAGS'];
			else:
				$count = count($results['FLAGS']);
				for ($i = 0; $i < $count; ++$i):
					$key = array_search($results['FLAGS'][$i], $this->messages[$uid]['flags']);
					if ($key === false):
						continue;
					endif;
					unset($this->messages[$uid]['flags'][$key]);
					$this->messages[$uid]['flags'] = array_values($this->messages[$uid]['flags']);
				endfor;
			endif;
		endif;
		return true;
	}
	
	
	/**
	 * Replace FLAGS data associated with a message in the selected mailbox.
	 * @param int
	 * @param int
	 * - self::FLAG_NONE
	 * - self::FLAG_ANSWERED
	 * - self::FLAG_FLAGGED
	 * - self::FLAG_DELETED
	 * - self::FLAG_SEEN
	 * - self::FLAG_DRAFT
	 * @return bool
	 * @see: https://datatracker.ietf.org/doc/html/rfc3501#section-6.4.6
	 */
	public function replaceFlags(int $uid, int $flags):bool {
		$values = [];
		if ($flags & self::FLAG_ANSWERED):
			$values[] = '\\Answered';
		endif;
		if ($flags & self::FLAG_FLAGGED):
			$values[] = '\\Flagged';
		endif;
		if ($flags & self::FLAG_DELETED):
			$values[] = '\\Deleted';
		endif;
		if ($flags & self::FLAG_SEEN):
			$values[] = '\\Seen';
		endif;
		if ($flags & self::FLAG_DRAFT):
			$values[] = '\\Draft';
		endif;
		if (empty($values)):
			return false;
		endif;
		$this->select();
		$command = sprintf('UID STORE %d FLAGS (%s)', $uid, implode(' ', $values));
		if (false === $this->connection->command($command)):
			return false;
		endif;
		if (false === (isset($this->connection->results['FETCH']) && is_array($this->connection->results['FETCH']))):
			return true;
		endif;
		$results = array_shift($this->connection->results['FETCH']);
		if (false === empty($results['FLAGS']) && is_array($results['FLAGS'])):
			if (false === isset($this->messages[$uid])):
				$this->messages[$uid] = [];
			endif;
			$this->messages[$uid]['flags'] = $results['FLAGS'];
		endif;
		return true;
	}
	
	
	/**
	 * 
	 */
	protected function getStructureSection(\stdClass $struct, string $field, $value):array {
		static $section = [];
		static $depth = -1;
		$result = [];
		if (isset($struct->$field) && $struct->$field === $value):
			if (false === empty($section)):
				$struct->section = implode('.', $section);
			endif;
			$result[] = $struct;
		elseif (isset($struct->parts)):
			$count = count($struct->parts);
			for ($i = 0; $i < $count; ++$i):
				++$depth;
				$section[$depth] = $i + 1;
				$array = $this->getStructureSection($struct->parts[$i], $field, $value);
				array_pop($section);
				--$depth;
				if (false === empty($array)):
					$result = array_merge($result, $array);
				endif;
			endfor;
		endif;
		if ($depth === -1):
			$section = [];
		endif;
		return $result;
	}
}

?>