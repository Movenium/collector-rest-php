# collector-rest-php

## Installation with composer
```
composer require movenium/collector-rest-php
```

## Usage

Login to capi

```
use movenium\collector;

$collector = new collector();
$collector->login("username", "password");
```

Fetching data

```
$collector->findAll("user");
```

## More info
https://api.movenium.com