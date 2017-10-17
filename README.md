# Netgen Layouts Relation List Query

This bundle provides Netgen Layouts query that makes it possible to add items to
block via relation list field type available in any content in eZ Publish CMS.

## Installation instructions

### Use Composer

Run the following from your installation root to install the package:

```bash
$ composer require netgen/layouts-relation-list-query:^1.0
```

### Activate the bundle in your app kernel

Add the following to the list of activated bundles:

```php
$bundles = array(
...

new Netgen\Bundle\LayoutsRelationListQueryBundle\NetgenLayoutsRelationListQueryBundle(),

...
);
```

Due to how prepending configuration of other bundles works in Symfony, to make
this query type display after the existing eZ Publish query type, you need to
add the bundle BEFORE `NetgenEzPublishBlockManagerBundle` in the list of
activated bundles.





Implementirati tag query koji:
ima sve postojeće paremetre standardnog eZ Query-ja 
(parent location, sort opcije, limit opcije, offset, fetch main loc, filter po content typeu)

manualni odabir jednog 
ili više tagova koji se koriste za dohvat 
(preko content browsera) 

- default OR logika između odabranih tagova, 
moguća opcija AND

mogućnost kontekstnog odabira, 
pri čemu se koriste tagovi sa trenutno prikazanog objekta, 
uz mogućnost definiranja jednog ili više fieldova koji se koriste 
(bitno za use case kada imamo više tag fieldova koji se koriste za specifične potrebe) 
- default OR logika, moguća opcija AND

