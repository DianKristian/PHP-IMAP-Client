<?php

require 'autoloader.php';

use Mailbox\Config;
use Mailbox\ConnectionSocket;
use Mailbox\Mailbox;

$config = new Config([
	'host' 		=> 'w019b785.kasserver.com', 
	'port' 		=> 993, 
	'username' 	=> 'm05f535f', 
	'password' 	=> '4fgpJHCYcc6muDTa',
	'secure' 	=> true,
	'timeout' 	=> 30,
	'debug' 	=> false,
	'auth_type' 	=> 'PLAIN',
	'proxy_host' 	=> '202.61.237.252',
	'proxy_port' 	=> 80,
	'proxy_username' 	=> 'tinyTest',
	'proxy_password' 	=> '2021Tiny1116',
]);
$mailbox = 'INBOX';
$messageId = 23980;
try {
	$imap = new ConnectionSocket($config);
	$mailbox = $imap->select($mailbox);
	// Check messages
	if ($mailbox->countMessages() > 0):
		// Get UID. Most of mailbox methods using UID for consistency
		$uid = $mailbox->getUid(1);
		if ($uid > 0):
			//$struct = $mailbox->getStructure($uid, true);
			$str = 'testä$ü';
			$str = 'testä$ü';
			if (false === $mailbox->checkFolder($str)):
				$mailbox->createFolder($str);
			endif;
			$folders = $mailbox->getFolders();
			//$envelope = $mailbox->getEnvelope($uid);
			//$replyto = $mailbox->getReplyTo($uid);
			//$from = $mailbox->getFrom($uid);
			//$subject = $mailbox->getSubject($uid);
			//$html = $mailbox->getHtmlBody($uid, true);
			//$plain = $mailbox->getTextBody($uid, true);
			//$attachments = $mailbox->getAttachments($uid);
			//$flagsBefore = $mailbox->getFlags($uid);
			//$mailbox->addFlags($uid, Mailbox::FLAG_SEEN|Mailbox::FLAG_FLAGGED);
			//$mailbox->removeFlags($uid, Mailbox::FLAG_FLAGGED);
			//$flagsAfter = $mailbox->getFlags($uid);
			
			// which file you want to download
			//$key = 0;
			
			/*
			if (isset($attachments[$key])):
				if (false === empty($attachments[$key]['disposition_parameter']['FILENAME'])):
					$filename = $attachments[$key]['disposition_parameter']['FILENAME'];
				elseif (false === empty($attachments[$key]['parameter']['NAME'])):
					$filename = $attachments[$key]['parameter']['NAME'];
				else:
					$filename = 'test';
					// @todo: get file extension from mime type
					if ($attachments[$key]['type'] === 'TEXT'):
						// @todo
					elseif ($attachments[$key]['type'] === 'IMAGE'):
						// @todo
					elseif ($attachments[$key]['type'] === 'AUDIO'):
						// @todo
					elseif ($attachments[$key]['type'] === 'VIDEo'):
						// @todo
					elseif ($attachments[$key]['type'] === 'APPLICATION'):
						// @todo
					endif;
				endif;
				
				$contents = $mailbox->getAttachmentData($uid, $attachments[$key]['section']);
				
				if ($attachments[$key]['encoding'] === 'BASE64'):
					$contents = base64_decode($contents);
				elseif ($attachments[$key]['encoding'] === 'QUOTED_PRINTABLE'):
				endif;
				
				file_put_contents($filename, $contents);
				
			endif;
			*/
		endif;
		/*
		*/
		var_dump(
			$uid,
			//$struct,
			$folders,
			//$envelope,
			//$replyto,
			//$from,
			//$subject,
			//$html,
			//$plain,
			//$attachments,
			//$flagsBefore,
			//$flagsAfter,
			//$mailbox
		);
	endif;
} catch (Error $e) {
	echo $e;
}
?>