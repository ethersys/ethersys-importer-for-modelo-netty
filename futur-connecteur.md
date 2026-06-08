
## 2. Architecture : préparer le système de connecteurs

C’est la pièce centrale du futur. Aujourd’hui le code mélange trois préoccupations :

- **Source** : « comment je récupère les données » (HTTP + parsing XML Netty).
- **Modèle** : « à quoi ressemble un bien dans mon plugin ».
- **Cible** (= sink) : « comment je matérialise un bien dans WordPress » (Houzez `fave_*` + Featured + Houzez agents + ImmoWP `dpeNumber` …).

Le sink Houzez est **codé en dur** dans `Importer::upsert_property`, `MediaSync::sync_gallery` (clés `fave_property_images`) et `Importer::apply_default_contact_agent`. Tant que ce couplage reste, ajouter ACF demande de dupliquer 80 % du fichier.

### 2.1 Proposition de découpage

```
Source        ─► PropertyRecord (DTO neutre) ─► Sink
NettyXmlSource                                  HouzezSink
(autres demain)                                 AcfSink
                                                CptRawSink (fallback générique)
```

- **`Source`** : produit des `PropertyRecord` (objet immutable / array typé). Implémente `iterate(): \Generator<PropertyRecord>`. Concrètement, on déplace `XmlParser::parse_bien()` dans `Sources\NettyXmlSource` qui consomme un flux et yield des records.
- **`PropertyRecord`** : DTO neutre, avec un schéma documenté (`reference`, `title`, `description`, `price`, `rent`, `surface`, `rooms`, `bedrooms`, `bathrooms`, `city`, `zip`, `lat/lng`, `images[]`, `features[]`, `dpe{}`, `transaction_type`, `property_type`, `is_featured`, …). C’est le **contrat stable** du plugin.
- **`Sink` (interface)** :

  ```php
  interface PropertySink {
      public function name(): string;
      public function settings_schema(): array;       // pour l’admin
      public function find_by_ref( string $ref ): ?int;
      public function upsert( PropertyRecord $rec, ImportContext $ctx ): int;  // retourne post_id
      public function delete( int $post_id, ImportContext $ctx ): void;
      public function sync_media( int $post_id, PropertyRecord $rec, ImportContext $ctx ): MediaResult;
      public function existing_refs(): array;          // index ref => post_id (cf §3.1)
  }
  ```

- **`Importer`** devient orchestrateur : il tient le verrou, le logger, demande au sink l’index existant, itère la source, appelle `find_by_ref` / `upsert` / `sync_media`, puis `delete` pour les manquants. **Il ne connaît plus aucune meta `fave_*`.**

### 2.2 Choix du sink

Stocker en option `nti_sink = 'houzez'` (par défaut). Auto-détecter (`function_exists('houzez_init')` ou taxonomie `property` connue) pour proposer la bonne valeur par défaut à l’activation. L’écran admin liste les sinks enregistrés via un filtre :

```php
$sinks = apply_filters( 'nti/sinks', [
    'houzez' => HouzezSink::class,
    'acf'    => AcfSink::class,
    'raw'    => CptRawSink::class,
] );
```

Chaque sink expose `settings_schema()` (clé → type → label) et l’écran d’admin rend dynamiquement le formulaire correspondant. Pour Houzez ça donne `default_agent_id`. Pour ACF, ce sera typiquement `field_group_key` + le mapping `record_field → acf_field_key`.

### 2.3 Mapping configurable

Aujourd’hui, le mapping Netty → Houzez est codé en dur dans `upsert_property`. Pour permettre à un utilisateur de surcharger sans patcher le code :

```php
$map = apply_filters( 'nti/sinks/houzez/field_map', [
    'price'             => 'fave_property_price',
    'rent'              => 'fave_property_price',
    'size_living'       => 'fave_property_size',
    'size_land'         => 'fave_property_land',
    'bedrooms'          => 'fave_property_bedrooms',
    // …
] );
```

Et le sink fait `update_post_meta($post_id, $map[$key], $value)`. Avantage : tests faciles (on injecte un mapping), évolutivité, possibilité de mapping côté admin (UI plus tard).

### 2.4 Cas DPE/GES

Aujourd’hui mélangé dans `upsert_property` et géré côté front par `DpeIntegration`. Devrait devenir un **module optionnel** branché sur le sink actif :

- Le module DPE écrit ses metas spécifiques (`dpeNumber`, `gesNumber`, …) via un hook `nti/after_upsert`.
- L’affichage front (déplacement DOM) reste un sous-système à part, indépendant du sink. Le rendre conditionnel à la présence du shortcode tiers (déjà fait) **et** à un opt-in admin (cocher « activer l’intégration ImmoWP DPE/GES ») — sinon c’est trop magique pour qui ne connaît pas la stack.

### 2.5 Sortir Houzez i18n et ThemeCompat du plugin

`ThemeCompat` et `HouzezSearchI18n` n’ont rien à voir avec l’import. Ce sont des **patches FR pour Houzez** qui squattent le scope. Un opérateur qui choisit le sink « ACF » se traîne quand même la franc-isation des libellés Houzez et le filtre `option_houzez_options`.

**Action** : extraire dans un plugin compagnon `houzez-fr-tweaks` (ou désactiver via une option), et au minimum ne charger ces classes que si Houzez est actif :

```php
if ( function_exists( 'houzez_init' ) ) {
    ThemeCompat::init();
    HouzezSearchI18n::init();
}
```

### 2.6 Pluggable côté source

À terme, une autre source XML (Apimo, Poliris, Hektor) ou un push webhook peut s’ajouter de la même façon. Mais c’est secondaire : le bénéfice principal est côté sink (les utilisateurs n’importent pas tous depuis Netty, alors que tous ont besoin que ça matche **leur** modèle de données).

---
