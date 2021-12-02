<?php
require 'autoloader.php';

use Mailbox\Config;
use Mailbox\Mailbox;

$config = new Config([
	'host' 		=> '', 
	'port' 		=> 993, 
	'username' 	=> '',    
	'password' 	=> '', 
	'secure' 	=> 'ssl',
	'timeout' 	=> 30,
	'debug' 	=> true,
	'auth_type' 	=> 'PLAIN',
	/**
	 * 
	 */ 
	'proxy_host' 	=> '',
	'proxy_port' 	=> 80,
	'proxy_username' 	=> '',
	'proxy_password' 	=> '',
]);

$folder = 'INBOX';
$messageSequenceNumber = 1;

try {
	//$imap = new \Mailbox\ConnectionCurl($config);
	$imap = new \Mailbox\ConnectionSocket($config);
	
	$imap->noop();
	
	// get list folders
	$list = $imap->list();
	//var_dump($list);
	
	// Prepare folder name to test create, rename, and delete methods
	$folder1 = 'testä$ü';
	$folder2 = 'test';
	
	// Make sure prepared folder name dosen't exists
	// Don't use real foldername
	foreach ($list as $object):
		if ($object->name === $folder1):
			$imap->delete($folder1);
		elseif ($object->name === $folder1):
			$imap->delete($folder2);
		endif;
	endforeach;
	
	// Create folder within root
	$imap->create($folder1);
	
	// rename folder 
	$imap->rename($folder1, $folder2);
	
	// delete folder
	$imap->delete($folder2);
	
	// Subscribe folder
	$imap->subscribe($folder);
	
	// get lsub
	$list = $imap->lsub();
	//var_dump($list);
	
	// Unsubscribe folder
	$imap->unsubscribe($folder);
	
	// get status
	$status = $imap->status($folder, $imap::STATUS_ALL);
	//var_dump($status);
	
	// now we will work on messages in specific folder
	// imap select command can't lock for specific folder when we work in multi folders
	// this class ensures that we work on messages in the locked folder
	$inbox = $imap->select('INBOX');
	$archive = $imap->select('Archiv');
	$draft = $imap->select('Entwürfe');
	$junk = $imap->select('Spam');
	$thrash = $imap->select('Papierkorb');
	$sent = $imap->select('Gesendet');
	//var_dump($inbox);
	// Requests a checkpoint of the currently selected mailbox.
	$inbox->check();
	
	// Check messages
	if ($inbox->countMessages() > 0):
		
		$messages = $inbox->getMessages();
		//var_dump($messages);
		
		//$inbox->copyMessage(1, $archive->getFolder());
		//$archive->deleteMessage(1);
		
		//$inbox->moveMessage(1, $archive->getFolder());
		//$archive->moveMessage(1, $inbox->getFolder());
		
		// Get UID. Most of mailbox methods using UID for consistency
		$uid = $inbox->getUid($messageSequenceNumber);
		if ($uid > 0):
			
			$struct = $inbox->getStructure($uid, true);
			//var_dump($struct);
			
			$envelope = $inbox->getEnvelope($uid);
			//var_dump($envelope);
			
			$replyto = $inbox->getReplyTo($uid);
			//var_dump($replyto);
			
			$from = $inbox->getFrom($uid);
			//var_dump($from);
			
			$subject = $inbox->getSubject($uid);
			//var_dump($subject);
			
			//$str = 'testä$ü';
			//if (false === $inbox->checkFolder($str)):
			//	$inbox->createFolder($str);
			//endif;
			//$folders = $inbox->getFolders();
			//$messages = $inbox->getMessages();
			$html = $inbox->getHtmlBody($uid);
			//var_dump($html);
			
			$plain = $inbox->getTextBody($uid);
			//var_dump($plain);
			
			$flagsBefore = $inbox->getFlags($uid);
			//var_dump($flagsBefore);
			$inbox->addFlags($uid, $inbox::FLAG_SEEN | $inbox::FLAG_FLAGGED);
			$inbox->removeFlags($uid, Mailbox::FLAG_FLAGGED);
			$flagsAfter = $inbox->getFlags($uid);
			//var_dump($flagsAfter);
			
			// Get message structure with disposition attachment
			$attachments = $inbox->getAttachments($uid);
			//var_dump($attachments);
			
			// which file you want to download
			$key = 0; // first attachment
			
			if (isset($attachments[$key])):
				$filename = 'test' . $key;
				
				// search filename within disposition_parameter
				if (false === empty($attachments[$key]->disposition_parameter) && is_array($attachments[$key]->disposition_parameter)):
					$count = count($attachments[$key]->disposition_parameter);
					for ($i = 0; $i < $count; ++$i):
						if (strtolower($attachments[$key]->disposition_parameter[$i]->attribute) !== 'filename'):
							continue;
						endif;
						$filename = $attachments[$key]->disposition_parameter[$i]->value;
						break;
					endfor;
				
				// search filename within parameter
				elseif (false === empty($attachments[$key]->parameter) && is_array($attachments[$key]->parameter)):
					$count = count($attachments[$key]->parameter);
					for ($i = 0; $i < $count; ++$i):
						if (strtolower($attachments[$key]->parameter[$i]->attribute) !== 'name'):
							continue;
						endif;
						$filename = $attachments[$key]->parameter[$i]->value;
						break;
					endfor;
				endif;
				
				$contents = $inbox->getAttachmentData($uid, $attachments[$key]->section);
				
				if (false === empty($attachments[$key]->encoding)):
					$attachments[$key]->encoding = strtolower($attachments[$key]->encoding);
					if ($attachments[$key]->encoding === 'base64'):
						$contents = base64_decode($contents);
					elseif ($attachments[$key]->encoding === 'quoted-printable'):
						$contents = quoted_printable_decode($contents);
					endif;
				endif;
				
				file_put_contents($filename, $contents);
				
			endif;
			/*
			*/
		endif;
	endif;
} catch (Error $e) {
	echo $e;
}

?>