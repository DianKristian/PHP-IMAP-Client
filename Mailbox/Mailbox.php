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
		$command = sprintf('%s "%s"', $this->readonly ? 'EXAMINE' : 'SELECT', $this->connection->toUTF7Imap($this->folder));
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->getErrorText());
		endif;
		$this->status = array_merge($this->status, $this->connection->getResults());
		return true;
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
		$result = $this->connection->getResults();
		$count = count($result['EXPUNGE']);
		for ($i = 0; $i < $count; ++$i):
			$sequence = $result['EXPUNGE'][$i];
			if (false === isset($this->uids[$sequence])):
				continue;
			endif;
			$uid = $this->uids[$sequence];
			unset($this->uids[$sequence]);
			unset($this->messages[$uid]);
		endfor;
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
			throw new \Error($this->connection->getErrorText());
		endif;
		$result = $this->connection->getResults();
		return isset($result['SEARCH']) ? $result['SEARCH'] : [];
	}
	
	
	/**
	 * Retrieves INTERNALDATE data associated with a message in the selected mailbox.
	 * @params int
	 * @return string
	 */
	public function getInternalDate(string $uid) {
		$this->select();
		$field = 'INTERNALDATE';
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->getErrorText());
		endif;
		$result = $this->connection->getResults();
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
			throw new \Error($this->connection->getErrorText());
		endif;
		$result = $this->connection->getResults();
		if (empty($result)):
			return false;
		endif;
		$result = array_shift($result);
		return $result[$field];
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
	public function copy(int $uid, string $newFolder):bool {
		$this->select();
		return $this->connection->command(sprintf('UID COPY %d "%s"', $uid, $this->connection->toUTF7Imap($newFolder)));
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
	public function getMessages() {
		$this->select();
		if (false === $this->connection->command('UID SEARCH ALL')):
			throw new \Error($this->connection->getErrorText());
		endif;
		return $this->connection->getResults();
	}
	
	
	/**
	 * Get unique identification (UID) for given message in selected folder
	 * @param int sequence number of message
	 * @return int if sequence number not found method will return 0
	 */
	public function getUid(int $sequenceNumber):int {
		if (false === isset($this->uids[$sequenceNumber])):
			$this->select();
			$field = 'UID';
			$command = sprintf('FETCH %d (%s)', $sequenceNumber, $field);
			if (false === $this->connection->command($command)):
				throw new \Error($this->connection->getErrorText());
			endif;
			$result = $this->connection->getResults();
			if (false === empty($result)):
				$result = array_shift($result);
				$result = $result[$field];
				$this->uids[$sequenceNumber] = $result;
			else:
				$result = false;
			endif;
			return $result;
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
			$field = $extensible? 'BODYSTRUCTURE' : 'BODY';
			$command = sprintf('UID FETCH %d %s', $uid, $field);
			if (false === $this->connection->command($command)):
				throw new \Error($this->connection->getErrorText());
			endif;
			$result = $this->connection->getResults();
			if (false === empty($result)):
				$result = array_shift($result);
				$result = $result[$field];
				if ($field === 'BODYSTRUCTURE'):
					$this->normalizeStructure($result);
					$this->messages[$uid]['structure'] = $result;
				endif;
			endif;
			return $result;
		endif;
		return $this->messages[$uid]['structure'];
	}
	
	
	/**
	 * Some server results using lowercase type and subtype.
	 * Function normalize string to be an uppercase string
	 * @return void
	 */
	protected function normalizeStructure(&$structure):void {
		if (isset($structure['type'])):
			$structure['type'] = strtoupper($structure['type']);
		endif;
		if (isset($structure['subtype'])):
			$structure['subtype'] = strtoupper($structure['subtype']);
		endif;
		if (isset($structure['parts'])):
			$count = count($structure['parts']);
			for ($i = 0; $i < $count; ++$i):
				$this->normalizeStructure($structure['parts'][$i]);
			endfor;
		endif;
	}
	
	
	/**
	 * Get all envelope information for given message in selected folder
	 * @param int message UID
	 * @return array with fields:
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
	public function getEnvelope(int $uid):array {
		if (false === isset($this->messages[$uid]['envelope'])):
			$this->select();
			$field = 'ENVELOPE';
			$command = sprintf('UID FETCH %d %s', $uid, $field);
			if (false === $this->connection->command($command)):
				throw new \Error($this->connection->getErrorText());
			endif;
			$result = $this->connection->getResults();
			if (false === empty($result)):
				$result = array_shift($result);
				$result = $result[$field];
				$this->messages[$uid]['envelope'] = $result;
			endif;
			return $result;
		endif;
		return $this->messages[$uid]['envelope'];
	}
	
	
	/**
	 * Return reply_to field email addresses information  for given message in selected folder
	 * @param int message UID
	 * @return array see: getEnvelope method
	 */
	public function getReplyTo(int $uid) {
		$result = $this->getEnvelope($uid);
		return $result[$this->connection::FIELD_ENVELOPE_REPLYTO];
	}
	
	
	/**
	 * Return from field of email addresses information  for given message in selected folder
	 * @param int message UID
	 * @return array see: getEnvelope method
	 */
	public function getFrom(int $uid) {
		$envelope = $this->getEnvelope($uid);
		return $envelope[$this->connection::FIELD_ENVELOPE_FROM];
	}
	
	
	/**
	 * Return subject field of email addresses information  for given message in selected folder
	 * @param int message UID
	 * @return string see: getEnvelope method
	 */
	public function getSubject(int $uid) {
		$envelope = $this->getEnvelope($uid);
		return (string)$envelope[$this->connection::FIELD_ENVELOPE_SUBJECT];
	}
	
	
	// Output only the html Body if exist
	public function getHtmlBody(int $uid, bool $peek = false):string {
		$structure = $this->getStructure($uid, true);
		if (empty($structure)):
			return '';
		endif;
		$section = $this->getStructureSection($structure, 'subtype', 'HTML');
		$section = array_shift($section);
		if (false === isset($section)):
			return '';
		endif;
		$field = $peek ? 'BODY.PEEK[%s]' : 'BODY[%s]';
		$field = sprintf($field, $section['section']);
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->getErrorText());
		endif;
		$field = sprintf('BODY[%s]', $section['section']);
		$result = $this->connection->getResults();
		$result = array_shift($result);
		return $result[$field];
	}
	
	
	// Output only the text body if exist
	public function getTextBody(int $uid, bool $peek = false):string {
		$structure = $this->getStructure($uid, true);
		if (empty($structure)):
			return '';
		endif;
		$section = $this->getStructureSection($structure, 'subtype', 'PLAIN');
		$section = array_shift($section);
		if (false === isset($section)):
			return '';
		endif;
		$field = $peek ? 'BODY.PEEK[%s]' : 'BODY[%s]';
		$field = sprintf($field, $section['section']);
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->getErrorText());
		endif;
		$field = sprintf('BODY[%s]', $section['section']);
		$result = $this->connection->getResults();
		$result = array_shift($result);
		return $result[$field];
	}
	
	
	/**
	 * (Inline and attached - Name, Extension, Name without extension, size, MimeType)
	 */
	public function getAttachments(int $uid):array {
		$structure = $this->getStructure($uid, true);
		if (false === empty($structure)):
			$structure = $this->getStructureSection($structure, 'disposition', 'ATTACHMENT');
		endif;
		return $structure;
	}
	
	
	/**
	 * If this informations not in getAttachmets - To get information to save attachments with file_out_contents)
	 */
	public function getAttachmentData(int $uid, string $section) {
		$this->select();
		$field = sprintf('BODY[%s]', $section);
		$command = sprintf('UID FETCH %d %s', $uid, $field);
		if (false === $this->connection->command($command)):
			throw new \Error($this->connection->getErrorText());
		endif;
		$result = $this->connection->getResults();
		$result = array_shift($result);
		return trim($result[$field]);
	}
	
	
	/**
	 * (By UID or message ID)
	 */
	public function moveMessages(int $uid, string $newFolder) {
		if (false === $this->copy($uid, $this->connection->toUTF7Imap($newFolder))):
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
		$result = $this->connection->getResults();
		if (false === empty($result)):
			$result = array_shift($result);
			$result = $result['FLAGS'];
			if (false === isset($this->messages[$uid]['flags'])):
				if (false === isset($this->messages[$uid])):
					$this->messages[$uid] = [];
				endif;
				$this->messages[$uid]['flags'] = $result;
			else:
				$count = count($result);
				for ($i = 0; $i < $count; ++$i):
					if (in_array($result[$i], $this->messages[$uid]['flags'])):
						continue;
					endif;
					$this->messages[$uid]['flags'][] = $result[$i];
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
		$result = $this->connection->getResults();
		if (false === empty($result)):
			$result = array_shift($result);
			$result = $result['FLAGS'];
			if (false === isset($this->messages[$uid]['flags'])):
				if (false === isset($this->messages[$uid])):
					$this->messages[$uid] = [];
				endif;
				$this->messages[$uid]['flags'] = $result;
			else:
				$count = count($result);
				for ($i = 0; $i < $count; ++$i):
					$key = array_search($result[$i], $this->messages[$uid]['flags']);
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
			$result = $this->connection->getResults();
			if (false === empty($result)):
				$result = array_shift($result);
				$result = $result['FLAGS'];
				$this->messages[$uid]['flags'] = $result;
			endif;
		endif;
		return $this->messages[$uid]['flags'];
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
		$result = $this->connection->getResults();
		if (false === empty($result)):
			$result = array_shift($result);
			$result = $result['FLAGS'];
			if (false === isset($this->messages[$uid])):
				$this->messages[$uid] = [];
			endif;
			$this->messages[$uid]['flags'] = $result;
		endif;
		return true;
	}
	
	
	/**
	 * 
	 */
	protected function getStructureSection(array $struct, string $field, $value):array {
		static $section = [];
		static $depth = -1;
		$result = [];
		if (isset($struct[$field]) && $struct[$field] === $value):
			if (false === empty($section)):
				$struct['section'] = implode('.', $section);
			endif;
			$result[] = $struct;
		elseif (isset($struct['parts'])):
			$count = count($struct['parts']);
			for ($i = 0; $i < $count; ++$i):
				++$depth;
				$section[$depth] = $i + 1;
				$array = $this->getStructureSection($struct['parts'][$i], $field, $value);
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