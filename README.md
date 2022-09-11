# Nsq PHP

<img src="https://github.com/nsqphp/nsqphp/raw/main/docs/logo.png" alt="" align="left" width="150">

PHP Client for [NSQ](https://nsq.io/).

[![Latest Stable Version](https://poser.pugx.org/nsq/nsq/v)](//packagist.org/packages/nsq/nsq) [![Total Downloads](https://poser.pugx.org/nsq/nsq/downloads)](//packagist.org/packages/nsq/nsq) [![License](https://poser.pugx.org/nsq/nsq/license)](//packagist.org/packages/nsq/nsq)
[![codecov](https://codecov.io/gh/nsqphp/nsqphp/branch/main/graph/badge.svg?token=AYUMC3OO2B)](https://codecov.io/gh/nsqphp/nsqphp) [![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fnsqphp%2Fnsqphp%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/nsqphp/nsqphp/main) [![telegram](https://raw.githubusercontent.com/aleen42/badges/master/src/telegram.svg)](http://t.me/grachevko)


This library follow [SemVer](https://semver.org/). Until version 1.0 will be released anything MAY change at any time, public API SHOULD NOT be considered stable. If you want use it before stable version was released install strict version without range.

Installation
------------

This library is installable via [Composer](https://getcomposer.org/):

```bash
composer require nsq/nsq
```

Requirements
------------

This library requires PHP 8.0 or later.

Although not required, it is recommended that you install the [phpinnacle/ext-buffer](https://github.com/phpinnacle/ext-buffer) to speed up [phpinnacle/buffer](https://github.com/phpinnacle/buffer) .

Features
--------

- [x] PUB
- [x] SUB
- [X] Feature Negotiation	
- [X] Discovery	
- [ ] Backoff	
- [X] TLS	
- [X] Deflate	
- [X] Snappy	
- [X] Sampling	
- [X] AUTH

Usage
-----

### Producer

```php
use Nsq\Producer;

$producer = Producer::create(address: 'tcp://nsqd:4150');

// Publish a message to a topic
$producer->publish('topic', 'Simple message');

// Publish multiple messages to a topic (atomically) 
$producer->publish('topic', [
    'Message one',
    'Message two',
]);

// Publish a deferred message to a topic
$producer->publish('topic', 'Deferred message', delay: 5000);
```

### Consumer

```php
use Nsq\Consumer;
use Nsq\Message;

$consumer = Consumer::create(
    address: 'tcp://nsqd:4150', 
    topic: 'topic',
    channel: 'channel',
    onMessage: static function (Message $message): Generator {
        yield $message->touch(); // Reset the timeout for an in-flight message        
        yield $message->requeue(timeout: 5000); // Re-queue a message (indicate failure to process)        
        yield $message->finish(); // Finish a message (indicate successful processing)        
    },
);
```

### Lookup

```php
use Nsq\Lookup;
use Nsq\Message;

$lookup = new Lookup('http://nsqlookupd0:4161');
$lookup = new Lookup(['http://nsqlookupd0:4161', 'http://nsqlookupd1:4161', 'http://nsqlookupd2:4161']);

$callable = static function (Message $message): Generator {
    yield $message->touch(); // Reset the timeout for an in-flight message        
    yield $message->requeue(timeout: 5000); // Re-queue a message (indicate failure to process)        
    yield $message->finish(); // Finish a message (indicate successful processing)        
};

$lookup->subscribe(topic: 'topic', channel: 'channel', onMessage: $callable);  
$lookup->subscribe(topic: 'anotherTopic', channel: 'channel', onMessage: $callable);

$lookup->unsubscribe(topic: 'local', channel: 'channel');
$lookup->stop(); // unsubscribe all  
```

### Integrations

- [Symfony](https://github.com/nsqphp/NsqBundle)

License:
--------

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
