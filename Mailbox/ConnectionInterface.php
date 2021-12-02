<?php
namespace Mailbox;
interface ConnectionInterface {
	public function command(string $command, ?string &$commandTag = null):bool;
	public function isAuthenticated():bool;
	public function isSelected():bool;
	public function getSelectedFolder():?string;
	//public function getResults();
	public function noop();
	public function logout():bool;
	public function authenticate();
	public function login(string $username, string $password);
	public function select(string $mailbox, bool $readonly = false);
	public function create(string $mailbox):bool;
	public function delete(string $mailbox):bool;
	public function rename(string $oldMailbox, string $newMailbox):bool;
	public function subscribe(string $mailbox):bool;
	public function unsubscribe(string $mailbox):bool;
	public function list(string $reference = '', string $mailbox = '*');
	public function lsub(string $reference = '', string $mailbox = '*');
	public function status(string $mailbox, int $flags = 0):array;
	
}

?>