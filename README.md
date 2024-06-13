# collector-rest-php

## Installation with composer
```
composer require movenium/collector-rest-php
```

## Usage

Login to capi

```
use Covenium\Collector;

$collector = new Collector();
$collector->login("username", "password");
```

Fetching data

```
$collector->findAll("user");
```

## More info
https://api.movenium.com