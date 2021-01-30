# Nsq PHP

<img src="https://github.com/nsqphp/nsqphp/raw/main/logo.png" alt="" align="left" width="150">

PHP Client for [NSQ](https://nsq.io/).

[![Latest Stable Version](https://poser.pugx.org/nsq/nsq/v)](//packagist.org/packages/nsq/nsq) [![Total Downloads](https://poser.pugx.org/nsq/nsq/downloads)](//packagist.org/packages/nsq/nsq) [![License](https://poser.pugx.org/nsq/nsq/license)](//packagist.org/packages/nsq/nsq)
[![codecov](https://codecov.io/gh/nsqphp/nsqphp/branch/main/graph/badge.svg?token=AYUMC3OO2B)](https://codecov.io/gh/nsqphp/nsqphp) [![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fnsqphp%2Fnsqphp%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/nsqphp/nsqphp/main)

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
- [ ] Discovery	
- [ ] Backoff	
- [ ] TLS	
- [ ] Snappy	
- [X] Sampling	
- [X] AUTH

Usage
-----

### Publish

```php
use Nsq\Producer;

$producer = new Producer(address: 'tcp://nsqd:4150');

// Publish a message to a topic
$producer->pub('topic', 'Simple message');

// Publish multiple messages to a topic (atomically) 
$producer->mpub('topic', [
    'Message one',
    'Message two',
]);

// Publish a deferred message to a topic
$producer->dpub('topic', 'Deferred message', delay: 5000);
```

### Subscription

```php
use Nsq\Consumer;
use Nsq\Protocol\Message;
use Nsq\Subscriber;

$consumer = new Consumer('tcp://nsqd:4150');
$subscriber = new Subscriber($consumer);

$generator = $subscriber->subscribe('topic', 'channel');
foreach ($generator as $message) {
    if ($message instanceof Message) {
        $payload = $message->body;

        // handle message

        $message->touch(); // Reset the timeout for an in-flight message        
        $message->requeue(timeout: 5000); // Re-queue a message (indicate failure to process)        
        $message->finish(); // Finish a message (indicate successful processing)        
    }
    
    // In case of nothing received during timeout generator will return NULL
    // Here we can do something between messages, like pcntl_signal_dispatch()
    
    // We can also communicate with generator through send
    // for example:

    // Gracefully close connection (loop will be ended)
    $generator->send(Subscriber::STOP); 
}
```

### Integrations

- [Symfony](https://github.com/nsqphp/NsqBundle)

License:
--------

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
