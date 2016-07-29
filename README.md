## ReMaIm – Redmine to Maniphest Importer

### Dependencies

You will need to have a copy of Phabricator's libphutil in your path.
We assume that you have installed arcanist via your package manager and that
libphutil sits in /usr/share/libphutil.

If such is not the case, you will have to adapt the path inside lib/Wizard.php

### Installation

```bash
composer install
```

### Usage

**Note**: All tasks and comments will be created by the user whose Phabricator
API key you will be using.

1. Copy remaim.yml-dist to remaim.yml and fill in your redmine and
phabricator credentials
2. php import.php


### Running tests

```bash
./vendor/bin/phpspec run
```