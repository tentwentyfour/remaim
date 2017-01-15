Contributing
============

ReMaIm is an open source, community-driven project. If you'd like to contribute,
feel free to do this, but remember to follow this few simple rules:

Development
-----------

The easiest way to run a redmine server – if you don't have one running anyway – is using a docker container:

```bash
docker run --rm -P --network=my_nw --name some-redmine redmine
```

There is of course a downside to this and that is not having any complex data for migration. One very valuable contribution would thus be a relatively large, anonymized dataset to be used during development.


Branching strategy
------------------

- For new features, or bugs base your changes on the `master` branch and open PRs against `master`

Coverage
--------

- All classes that interact solely with the core logic should be covered by Specs
- All features should be covered with .feature descriptions automated with Behat but we realize they're not yet

Code style / Formatting
-----------------------

- All new classes must carry the standard copyright notice docblock
- All code in the `src` folder must follow the PSR-2 standard


So what remains to be done?
---------------------------

A sh*tload of things! Take a look at the issue section on github to see what is most urgent or popular.
