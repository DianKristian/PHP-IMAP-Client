# PHP-IMAP-Client
## REQUIREMENTS
## CONNECTION
## NOOP
```php
$imapConnection->noop();
```
The NOOP command always succeeds.  It does nothing. Since any command can return a status update as untagged data, the NOOP command can be used as a periodic poll for new messages or message status updates during a period of inactivity (this is the preferred method to do this). The NOOP command can also be used to reset any inactivity autologout timer on the server.
https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.2
## authenticate
### logout
### login
### select
### create
### delete
### rename
### subscribe
### unsubscribe
### list
### lsub
### status
### decodeMimeHeader
