# PHP-IMAP-Client
## REQUIREMENTS
## CONNECTION
### 1.1 ```noop```
#### 1.1.1 Description
The NOOP command always succeeds.  It does nothing. Since any command can return a status update as untagged data, the NOOP command can be used as a periodic poll for new messages or message status updates during a period of inactivity (this is the preferred method to do this). The NOOP command can also be used to reset any inactivity autologout timer on the server.
```php
$imapConnection->noop();
```
**Reference:** [RFC3501 section 6.1.2](https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.2)

### 1.2 ```authenticate```
Indicates a [SASL](https://datatracker.ietf.org/doc/html/rfc2222) authentication mechanism to the server. If the server supports the requested authentication mechanism, it performs an authentication protocol exchange to authenticate and identify the client.
```php
$imapConnection->authenticate();
```
**Reference:** [RFC3501 section 6.2.2](https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.2)

### 1.3 ```logout```
Informs the server that the client is done with the connection.
```php
$imapConnection->logout();
```
**Reference:** [RFC3501 section 6.1.3](https://datatracker.ietf.org/doc/html/rfc3501#section-6.1.3)

### 1.4 ```login```
Identifies the client to the server and carries the plaintext password authenticating this user.
```php
$imapConnection->login($username, $password);
```
**Reference:** [RFC3501 section 6.2.3](https://datatracker.ietf.org/doc/html/rfc3501#section-6.2.3)

### 1.5 ```select```
Selects a mailbox so that messages in the mailbox can be accessed.
```php
$imapConnection->select($mailbox, $readonly = false);
```
**Reference:** [RFC3501 section 6.3.1](https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.1)

### 1.6 ```create```
Creates a mailbox with the given name.
```php
$imapConnection->create($mailbox);
```
**Reference:** [RFC3501 section 6.3.3](https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.3)

### 1.7 ```delete```
Permanently removes the mailbox with the given name.
```php
$imapConnection->delete($mailbox);
```
**Reference:** [RFC3501 section 6.3.4](https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.4)

### 1.8 ```rename```
Changes the name of a mailbox.
```php
$imapConnection->rename($oldMailbox, $newMailbox);
```
**Reference:** [RFC3501 section 6.3.5](https://datatracker.ietf.org/doc/html/rfc3501#section-6.3.5)

### 1.9 ```subscribe```
Adds the specified mailbox name to the server's set of "active" or "subscribed" mailboxes as returned by the LSUB command.

### 1.10 ```unsubscribe```
Removes the specified mailbox name from the server's set of "active" or "subscribed" mailboxes as returned by the LSUB command.

### 1.11 ```list```
Returns a subset of names from the complete set of all names available to the client.

### 1.12 ```lsub```
Returns a subset of names from the set of names that the user has declared as being "active" or "subscribed".

### 1.13 ```status```
Requests the status of the indicated mailbox.

### 1.14 ```decodeMimeHeader```
Decode subject header

## 2. ```mailbox```
### 2.1. ```check```
### expunge
### getUid
### getInternalDate
### getSize
### getStructure
### getEnvelope
### countMessages
### getMessages
### copyMessage
### moveMessage
### deleteMessage
### getReplyTo
### getFrom
### getSubject
### getBody
### getHtmlBody
### getTextBody
### getAttachments
### getAttachmentData
### getFlags
Retrieves FLAGS data associated with a message in the selected mailbox.

### addFlags
Add FLAGS data associated with a message in the selected mailbox.

### removeFlags
Remove FLAGS data associated with a message in the selected mailbox.

### replaceFlags
Replace FLAGS data associated with a message in the selected mailbox.
