# Nsq PHP

<img src="https://github.com/nsqphp/nsqphp/raw/main/logo.png" alt="" align="left" width="150">

A NSQ Client library for PHP.

[![Latest Stable Version](https://poser.pugx.org/nsq/nsq/v)](//packagist.org/packages/nsq/nsq) [![Total Downloads](https://poser.pugx.org/nsq/nsq/downloads)](//packagist.org/packages/nsq/nsq) [![Latest Unstable Version](https://poser.pugx.org/nsq/nsq/v/unstable)](//packagist.org/packages/nsq/nsq) [![License](https://poser.pugx.org/nsq/nsq/license)](//packagist.org/packages/nsq/nsq)
[![codecov](https://codecov.io/gh/nsqphp/nsqphp/branch/main/graph/badge.svg?token=AYUMC3OO2B)](https://codecov.io/gh/nsqphp/nsqphp)

Installation
------------

This library is installable via [Composer](https://getcomposer.org/):

```bash
composer require nsq/nsq
```

Requirements
------------

This library requires PHP 7.4 or later.

Although not required, it is recommended that you install the [phpinnacle/ext-buffer](https://github.com/phpinnacle/ext-buffer) to speed up [phpinnacle/buffer](https://github.com/phpinnacle/buffer) .

Features
--------

- [x] PUB
- [x] SUB
- [ ] Feature Negotiation	
- [ ] Discovery	
- [ ] Backoff	
- [ ] TLS	
- [ ] Snappy	
- [ ] Sampling	
- [ ] AUTH

Usage
-----

### Publish

```php
use Nsq\Writer;

$writer = new Writer(address: 'tcp://nsqd:4150');

// Publish a message to a topic
$writer->pub('topic', 'Simple message');

// Publish multiple messages to a topic (atomically) 
$writer->mpub('topic', [
    'Message one',
    'Message two',
]);

// Publish a deferred message to a topic
$writer->dpub('topic', 5000, 'Deferred message');
```

### Subscription

```php
use Nsq\Envelope;
use Nsq\Subscriber;

$subscriber = new Subscriber(address: 'tcp://nsqd:4150');

$generator = $subscriber->subscribe('topic', 'channel', timeout: 5);
foreach ($generator as $envelope) {
    if (null === $envelope) {
        // No message received while timeout
        // Good place to pcntl_signal_dispatch() or whatever
        
        continue;
    }

    if ($envelope instanceof Envelope) {
        $payload = $envelope->message->body;

        // handle message

        $envelope->touch(); // Reset the timeout for an in-flight message        
        $envelope->requeue(timeout: 5000); // Re-queue a message (indicate failure to process)        
        $envelope->finish(); // Finish a message (indicate successful processing)        
    }
    
    if ($stopSignalReceived) {
        $generator->send(true); // Gracefully close connection
    }
}
```

### Integrations

- [Symfony](https://github.com/nsqphp/NsqBundle)

License:
--------

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
