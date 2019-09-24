# Netgen Layouts query based on Netgen Tags for eZ Platform

This bundle provides Netgen Layouts query that makes it possible to add items to
block via Tags field type available in any content in eZ Platform CMS.

## Installation instructions

### Use Composer

Run the following from your installation root to install the package:

```bash
$ composer require netgen/layouts-ezplatform-tags-query
```

### Activate the bundle in your app kernel

Add the following to the list of activated bundles:

```php
$bundles = [
...

new Netgen\Bundle\LayoutsEzPlatformTagsQueryBundle\NetgenLayoutsEzPlatformTagsQueryBundle(),

...
];
```

Due to how prepending configuration of other bundles works in Symfony, to make
this query type display after the existing eZ Platform query type, you need to
add the bundle BEFORE `NetgenLayoutsEzPlatformBundle` in the list of
activated bundles.

