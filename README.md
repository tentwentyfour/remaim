ReMaIm – Redmine to Maniphest Importer
======================================

[![Build Status](https://travis-ci.org/tentwentyfour/remaim.svg?branch=master)](https://travis-ci.org/tentwentyfour/remaim)

Dependencies
------------

You will need to have a copy of Phabricator's libphutil in your path.
We assume that you have installed arcanist via your package manager and that
libphutil sits in /usr/share/libphutil.

If such is not the case, you will have to adapt the path inside lib/Wizard.php

Installation
------------

Clone the project from https://github.com/tentwentyfour/remaim, then run

```bash
composer install
```

Usage
-----

**Note**: All tasks and comments will be created by the user whose Phabricator
API key you will be using.
We recommend to create a bot account that has access to all projects on your Phabricator instance.

The tool currently also presumes that you have created user accounts for all your collaborators in Phabricator since the Conduit API does not allow to create new accounts.

Users will be looked up by their full names, so make sure your users have the same full names in both Redmine and Phabricator before launching the migration process.


1. Copy remaim.yml-dist to remaim.yml and fill in your redmine and
phabricator credentials
2. php bin/remaim (or ./vendor/bin/remaim)


Forcing protocols
-----------------

Sometimes, Redmine will return attachment URLs using the http protocol, even though your instance is only reachable via https (Usually when you're using a reverse proxy and didn't set https to yes in the redmine configuration).

In that case, you need to specify a `protocol` in the config file's Redmine section to have remaim change it before retrieving attachments.

If you leave the `protocol` field empty, remaim will use the protocol returned by Redmine.


Running tests
-------------

```bash
./vendor/bin/phpspec run
```

Contributing
------------

Please see CONTRIBUTING.md for information on how you may contribute to this project.


FAQ
---

Q: But, but, it's not entirely done yet, why are you releasing this half-done tool to the public?
A: "Release early, release often" We believe the tool is in a state where many people can profit from using it and can adjust or extend its behavior with moderate effort. We've been using it to migrate over 4000 issues.

Plus, finishing ALL the things we would like to see would really take a long time, so we're hoping the community will pick this up, improve on it and send us plenty of PRs ;)
