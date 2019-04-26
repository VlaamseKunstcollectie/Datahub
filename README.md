# Datahub

[![Software License][ico-license]](LICENSE)
[![Build Status](https://travis-ci.org/thedatahub/Datahub.svg?branch=master)](https://travis-ci.org/thedatahub/Datahub)

The Datahub is a metadata aggregator. This application allows data providers to 
aggregate and publish metadata describing objects on the web through a RESTful 
API leveraging standardized exchange formats.

The Datahub is build with the [Symfony framework](https://symfony.com) and 
[MongoDB](https://www.mongodb.org).

## Features

* A RESTful API which supports:
  * Ingest and retrieval of individual metadata records.
  * Validation of ingested records against XSD schemas.
  * Supports OAuth to restrict access to the API.
* An OAI-PMH endpoint for harvesting metadata records.
* Includes support for [LIDO XML](http://lido-schema.org/) but can be extended 
to include MARC XML, Dublin Core or other formats.

## Requirements

This project requires following dependencies:

* PHP = 5.6.* or 7.0.*
  * With the php-cli, php-intl, php-mbstring and php-mcrypt extensions.
  * The [PECL Mongo](https://pecl.php.net/package/mongo) (PHP5) or [PECL Mongodb](https://pecl.php.net/package/mongodb) (PHP7) extension. Note that the _mongodb_ extension must be version 1.2.0 or higher. Notably, the package included in Ubuntu 16.04 (_php-mongodb_) is only at 1.1.5.
* MongoDB >= 3.2.10

## Install

- If you want to run a datahub instance in a virtual box:
    - Install your (virtualbox)[https://github.com/VlaamseKunstcollectie/Imagehub-box].
    - git clone the (datahub)[https://github.com/VlaamseKunstcollectie/datahub] into your shared vagrant folder. 
- while in the Imagehub-Box directory, `vagrant ssh` in to your box, and navigate to `/vagrant/datahub`  
- `composer install` in the datahub box
- get into mongo shell by typing `mongo`. If you get a LANG_LC error, then:
	- `export LC_ALL=C`
- once you're in your mongo shell you need to create a dbuser for the datahub that will be used to have vagrant be authenticated while trying to create the Datahub
- `db.createUser(
   {
     user: "datahub",
     pwd: "password",
     roles: [ "readWrite", "dbAdmin" ]
   }
)`
- if you get an authentication error while trying to set up the user above, first log in as admin. To do so, log in in your mongo shell with the credentials given in ansible (ansible/group_vars/all/mongo.yml), e.g. `-u SiteRootAdmin -p passw0rd -authenticationDatabase admin`
- run `app/console app:setup`
- configure swiftmailer:
	- go to config_dev.yml in your datahub folder
	- edit the swiftmailer info  at the bottom of that file
	- you need to put an email access token in the 'password' field for switfmailer. to get an access token for a gmail account, go to [your security settings in Google](https://myaccount.google.com/security), and under the 'Signing in to Google' card you need to click on 'App password'.  enter your google password if prompted and then create a new app password, for the app 'Mail'. You can choose to enter 'datahub' as its device, so you can remember what this app password is for again. You'll receive a password combination, which you need to enter in the config_dev folder. It's normal that there are no spaces in the password. 
- surf to datahub.box, you should get a login screen.
	- If you get a connectionexception make sure the datahub user and password you made in shell is the same as parameters.yml
	- create an admin account and enter the email address you just gave access to in config_dev. You should get an email that says 'welcome X!' and contains a one-time login. Follow the URL and you'll be logged in to your admin account. Go to your admin profile settings in the top right and change your password to something you will remember. 
- you should now have a working datahub instance with an admin user!

## Usage

### Credentials

The application is installed with as default username `admin` and as default password `datahub`. Changing this is highly recommended.

### The REST API

The REST API is available at `api/v1/data`. Documentation about the available 
API methods can be found  at `/docs/api`.

#### POST and PUT actions

The PUT and POST actions expect and XML formatted body in the HTTP request. 
The Content-Type HTTP request header also needs to be set accordingly. 
Currently, supported: `application/lido+xml`. Finally, you will need to add a 
valid OAuth token via the `access_token` query parameter.

A valid POST HTTP request looks like this:

```
POST /api/v1/data?access_token=MThmYWMxMjFlZWZmYjVmZDU2NDNmZWIzYTE0YmNiYTk3YTc5ODJmMWJjOGI1MjE5MWY4ZjEyZWZlZmM2ZmZmNg HTTP/1.1
Host: example.org
Content-Type: application/lido+xml
Cache-Control: no-cache

<?xml version="1.0" encoding="UTF-8"?>
<lido:lido xmlns:lido="http://www.lido-schema.org" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.lido-schema.org http://www.lido-schema.org/schema/v1.0/lido-v1.0.xsd">
	<lido:lidoRecID lido:source="Deutsches Dokumentationszentrum für Kunstgeschichte - Bildarchiv Foto Marburg" lido:type="local">DE-Mb112/lido-obj00154983</lido:lidoRecID>
	<lido:category>
...
```

#### GET actions

Sending a GET HTTP request to the `api/v1/data` endpoint will return a 
paginated list of all the records available in the API. The endpoint will 
return a HTTP response with a JSON formatted body. The endpoint respects the 
[HATEOAS](https://en.wikipedia.org/wiki/HATEOAS) constraint.

Content negotation is currently only supported via a file extension on 
individual resource URL's. Negotation via the HTTP Accept header is on the 
roadmap.

```
GET api/v1/data               # only JSON supported
GET api/v1/data/objectPID     # return JSON
GET api/v1/data/objectPID.xml # return XML
```

### The OAI endpoint

The datahub supports the [OAI-PMH protocol](https://www.openarchives.org/OAI/openarchivesprotocol.html). 
The endpoint is available via the `/oai` path.

```
GET oai/?metadataPrefix=oai_lido&verb=ListIdentifiers
GET oai/?metadataPrefix=oai_lido&verb=ListRecords
GET oai/?metadataPrefix=oai_lid&verb=GetRecord&identifier=objectPID
GET oai/?metadataPrefix=oai_lid&verb=GetRecord&identifier=objectPID
GET oai/?metadataPrefix=oai_lido&verb=ListIdentifiers&from=2017-06-29T05:22:30Z&until=2017-07-14T04:22:30Z
```

The datahub doesn't implement grouping of records nor soft deletes. As such, 
the OAI endpoint doesn't OAI sets and indicating whether a record has been 
deleted.

### OAuth support and security

The datahub API can be set up to be either a public or a private API. The 
`public_api_method_access` parameter in `parameters.yml` allows you to 
configure which parts of the API are public or private:

`````YAML
    # Setting this to some unknown value like [FOO] disables public api access
    # Leaving this option empty [] means allowing all methods for anonymous access
    # public_api_method_access: [FOO]
    public_api_method_access: [GET]
`````

The datahub requires OAuth authentication to ingest or retrieve metadata 
records. The administrator has to issue a user account with a client_id and a 
client_secret to individual Users or client applications. Before clients can 
access the API, they have to request an access token:

```bash
curl 'http://localhost:8000/oauth/v2/token?grant_type=password&username=admin&password=datahub&client_id=slightlylesssecretpublicid&client_secret=supersecretsecretphrase'
```

Example output:

```
{
    "access_token": "ZDIyMGFiZGZkZWUzY2FjMmY4YzNmYjU0ODZmYmQ2ZGM0NjZiZjBhM2Q0Y2ZjMGNiMjc0ZWIyMmYyODMzMGJjZg",
    "expires_in": 3600,
    "token_type": "bearer",
    "scope": "internal web external",
    "refresh_token":  "MzhkYzY0MzMxM2FmNmQyODhiOWM4YzEzZjI3YzViZjg3ZThlMTA2YWY4ZTc2YjUwYzgxNzVhNTlmYTBkYWZhNQ"
}
```

The endpoint can also be used to revoke both access and refresh tokens.

```
curl 'http://localhost:8000/oauth/v2/revoke?token=ZDIyMGFiZGZkZWUzY2FjMmY4YzNmYjU0ODZmYmQ2ZGM0NjZiZjBhM2Q0Y2ZjMGNiMjc0ZWIyMmYyODMzMGJjZg'
```

Example output:

```
{
    "result": "success",
    "message": "The token has been revoked."
}
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed 
recently.

## Testing

Testing will require a MongoDB instance, as well as Catmandu installed. You 
can either take care of this yourself, or run the tests using the provided 
Docker container.

Please ensure you've taken care of the initial setup described above before 
attempting to run the tests.

Running tests:

```
./scripts/run_tests
```

Running tests using Docker:

```
./scripts/run_tests_docker
```

## Front end development

Front end workflows are managed via [yarn](https://yarnpkg.com/en/) and 
[webpack-encore](https://symfony.com/blog/introducing-webpack-encore-for-asset-management).

The layout is based on [Bootstrap 3.3](https://getbootstrap.com/docs/3.3/) 
and managed via sass. The code can be found under `app/resources/public/sass`. 

Javascript files can be found under `app/resources/public/js`. Dependencies are 
managed via `yarn`. Add vendor modules using `require`.

Files are build and stored in `web/build` and included in `app/views/app/base.html.twig`
via the `asset()` function.

The workflow configuration can be found in `webpack.config.js`.

Get started:

```
# Install all dependencies
$ yarn install
# Build everything in development
$ yarn run encore dev
# Watch files and build automatically
$ yarn run encore dev --watch
# Build for production
$ yarn run encore production
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Authors

[All Contributors][link-contributors]

## Copyright and license

The Datahub is copyright (c) 2016 by Vlaamse Kunstcollectie vzw and PACKED vzw.

This is free software; you can redistribute it and/or modify it under the 
terms of the The GPLv3 License (GPL). Please see [License File](LICENSE) for 
more information.

[ico-version]: https://img.shields.io/packagist/v/:vendor/:package_name.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-GPLv3-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/:vendor/:package_name/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/:vendor/:package_name.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/:vendor/:package_name.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/:vendor/:package_name.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/:vendor/:package_name
[link-travis]: https://travis-ci.org/:vendor/:package_name
[link-scrutinizer]: https://scrutinizer-ci.com/g/:vendor/:package_name/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/:vendor/:package_name
[link-downloads]: https://packagist.org/packages/:vendor/:package_name
[link-author]: https://github.com/:author_username
[link-contributors]: ../../contributors
