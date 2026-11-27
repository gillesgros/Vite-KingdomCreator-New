# Suivi d’usage du Dominion Randomizer

## Installation

Le déploiement publie le build Vue (`dist/`), `server.php` et `dashboard.php` à
la racine du répertoire web de `suivi.71yeti.fr`. `server.php` est le point
d’entrée de collecte utilisé par les clients ; `dashboard.php` sert le reporting
et la maintenance. Ils partagent `analytics-common.php`, le schéma SQLite et le
répertoire `databases/`. Le serveur doit disposer de PHP avec PDO SQLite
(`pdo_sqlite`) et pouvoir créer ce répertoire accessible en écriture. Il faut
également installer le lecteur GeoIP et la base GeoLite2 décrits ci-dessous ;
ils doivent rester hors du répertoire web.

Configurer une variable d’environnement serveur `ANALYTICS_DASHBOARD_TOKEN` avec
un jeton administrateur long et aléatoire. Si l’hébergeur ne permet pas de
configurer une variable d’environnement, créer sur le serveur (sans l’ajouter au
dépôt) un fichier `server-config.php` à côté de `server.php` :

```php
<?php
return ['dashboardToken' => 'REMPLACER_PAR_UN_JETON_LONG_ET_ALEATOIRE'];
```

Le jeton protège les statistiques, les journaux et les opérations de maintenance ;
il n’est jamais inclus dans les événements. Sans jeton configuré, la collecte
des événements fonctionne, mais les lectures du tableau de bord renvoient une erreur.

Ouvrir `https://suivi.71yeti.fr/` pour le tableau de bord, sélectionner la source
du site et saisir le jeton. Les données brutes s’affichent d’abord avec un filtre
par `EventType` et une pagination ; les statistiques sont réparties entre les
onglets de synthèse et d’événements.
Le bouton indique « Charger le dashboard » avant le premier chargement réussi
d’une source, puis « Mettre à jour le dashboard ». Changer de source non encore
chargée rétablit le libellé de chargement.
Les colonnes des tableaux de données brutes et des royaumes récents sont
redimensionnables ; leurs largeurs sont conservées dans le navigateur.

## Déploiement depuis le dépôt

Depuis la racine du dépôt, lancer :

```sh
bun run deploy:follow
```

Quand la base principale nécessite la migration, le script demande confirmation
avant de déplacer ou d’importer des données. Pour choisir sans invite :

```sh
bun run deploy:follow -- --migrate-database
bun run deploy:follow -- --skip-database-migration
bun run deploy:follow -- --migrate-remaining-databases
```

Sans TTY, la migration est ignorée par défaut ; passez explicitement
`--migrate-database` pour l’exécuter. Une base déjà migrée est détectée et la
migration n’est pas relancée, même avec cette option. L’option
`--migrate-remaining-databases` déplace et importe aussi les autres sources ;
ne l’utiliser que lorsqu’elles ne reçoivent pas de requêtes.

Pour vérifier uniquement la syntaxe des fichiers PHP sur IONOS, sans déployer,
lancer :

```sh
bun run lint:php:follow
```

Cette commande transfère temporairement les deux fichiers serveur, les helpers
et les trois scripts de migration dans un dossier privé sous le compte SSH,
exécute `/usr/bin/php8.5-cli -l` sur chacun, puis supprime les copies
temporaires.
Si la clé ou la configuration SSH manque, elle lance d’abord l’assistant de
configuration en simulation : aucun build ni déploiement n’est effectué. Cette
initialisation autorise toutefois la clé publique sur IONOS et remplace les
entrées `authorized_keys` portant le commentaire `ionos-follow`.

Au premier lancement du lint ou du déploiement sur un poste, l’assistant demande
l’utilisateur et l’hôte SSH IONOS. Il requiert `ssh` et `ssh-keygen` dans le
`PATH` pour cette initialisation. Il génère une clé Ed25519 sans passphrase dans
`src/analytics/server/keys/ionos_follow`, puis demande le mot de passe SSH pour
ajouter la clé publique au compte IONOS. Le mot de passe n’est pas enregistré.
La configuration locale est conservée dans
`src/analytics/server/keys/.env.do_not_commit` ; le dossier `keys/` est ignoré
par Git. Cette exclusion ne protège pas les fichiers contre les autres comptes
Windows ni contre un accès à votre poste : protégez ce compte et ne partagez
jamais la clé privée.

Chaque clone ou nouveau poste doit refaire cette initialisation : les réglages
et la clé privée ne sont pas inclus dans le dépôt. L’assistant remplace sur le
serveur les entrées `authorized_keys` dont le commentaire contient
`ionos-follow` avant d’ajouter la nouvelle clé. Ainsi, initialiser un autre poste
rend inutilisable l’ancienne clé de déploiement portant ce commentaire.

Les valeurs par défaut sont le répertoire distant `~/KingdomCreator/Follow` et
le vérificateur PHP `/usr/bin/php8.5-cli`. Elles peuvent être modifiées dans le
fichier local `.env.do_not_commit` (`IONOS_REMOTE_DIR` et `IONOS_PHP_CLI`).
`IONOS_SSH_USER`, `IONOS_SSH_HOST` et `IONOS_SSH_KEY` y sont également stockés.
Pour les déploiements, `IONOS_SSH_EXE` et `IONOS_SCP_EXE` peuvent remplacer les
exécutables `ssh` et `scp` trouvés dans le `PATH`.

Le déploiement construit d’abord le dashboard Vue dans `src/analytics/server/`,
puis transfère les deux points d’entrée, leurs helpers, les commandes CLI et le
contenu de `dist/` dans le répertoire web IONOS. Il vérifie la syntaxe PHP avant
installation. Il installe d’abord les helpers et les scripts, puis retire
brièvement `server.php` et `dashboard.php` du chemin uniquement si la migration
a été confirmée, afin d’empêcher les écritures et lectures pendant le déplacement
de la base principale. Les requêtes reçues pendant cette courte interruption ne
sont pas mises en file. La migration déplace `databases/dominionrandomizer.com.db` vers
`<répertoire-web>-backups/dominionrandomizer.com-v7.2-moved.db`, crée et initialise
la base active, installe les deux entrées, puis importe les anciennes lignes
sans écraser celles éventuellement déjà reçues dans la base active. Sans
migration, le déploiement ne déplace ni n’importe les bases et archives ; les
entrées conservent toutefois leur migration automatique de schéma à la première
requête si une base historique en a encore besoin. Enfin, il publie le dashboard.
Les autres bases ne sont ni déplacées ni migrées par `deploy:follow`, sauf avec
`--migrate-remaining-databases`.

L’opération peut être relancée : le déplacement n’écrase jamais une archive
`-v7.2-moved.db` existante. Après l’import, `PRAGMA user_version` de la base
active vaut `1` et le manifeste conserve aussi la trace de l’archive importée.
Pour les bases importées avant l’ajout de cette version, le manifeste existant
permet au déploiement d’inscrire `user_version=1` sans réimporter les données.
Si une étape échoue pendant la préparation de la migration, le script restaure
les points d’entrée mis en pause lorsque leurs remplaçants ne sont pas installés ;
l’archive est conservée pour reprendre la préparation ou l’import. Après la publication de `index.html`,
les anciens fichiers de `assets/` absents du nouveau build sont supprimés. Les
scripts CLI temporaires de migration sont retirés de `scripts/` à la fin, puis
ce répertoire est supprimé s’il est vide. `database-migration.php` et
`analytics-common.php` restent à la racine pour les deux entrées ; `dashboard.php`
charge aussi `analytics-reporting.php`. Le déploiement ne transfère ni ne supprime
`server-config.php` ou `index-2.html`.

Pour simuler le déploiement après l’initialisation, définir
`IONOS_DEPLOY_DRY_RUN=1` dans l’environnement du terminal avant de lancer la
commande. Sur un clone non initialisé, le premier lancement configure quand
même l’accès SSH avant d’effectuer la simulation.

## Gestion des données

### Migration et performances SQLite

Chaque source reste stockée dans son propre fichier `databases/<source>.db`.
Les nouvelles bases créent `RequestLog` avec les colonnes scalaires `country`,
`device` et `browser`, en plus du JSON `clientContext` conservé pour compatibilité.
La base principale est déplacée réellement (et non copiée) dans le répertoire
frère privé `<répertoire-web>-backups/` avant l’installation de `server.php`.
Elle est ensuite importée dans le nouveau fichier actif, avec préservation des
identifiants historiques sauf collision. Le helper partagé applique les colonnes
historiques/scalaires, le remplissage depuis `clientContext` et les six index.
Le serveur et les scripts CLI utilisent le même helper pour éviter toute
divergence. Une source sans fichier `.db` est créée avec le nouveau schéma à sa
première requête de collecte vers `server.php` ; elle n’apparaîtra dans `listSources` qu’après
cette création.

Les autres bases, supposées sans trafic, sont reportées par défaut. Quand vous
déciderez de les traiter, lancez `bun run deploy:follow -- --migrate-remaining-databases`
et confirmez aussi la migration principale si celle-ci est encore en attente.
Cette option exclut `dominionrandomizer.com`, déplace chaque autre base vers son
archive `-v7.2-moved.db` puis l’importe dans son fichier actif. Les scripts
temporaires sont supprimés après le déploiement ; ne renommez pas manuellement
les bases avant de lancer cette commande. Les anciennes archives
`<source>-v7.2.db` ne sont pas écrasées.

La sauvegarde d'une base déjà archivée n'est pas écrasée lors d'une nouvelle
tentative de déploiement. Les archives sont hors du répertoire web et sont
créées avec des permissions privées. Le serveur attend
jusqu'à 30 secondes un verrou SQLite pendant l'initialisation/migration ; une
première requête sur une grande base peut donc attendre et sera plus lente. Le
temps de migration déclenché par le serveur apparaît dans `Server-Timing` sous
`metadata_migration`.

Le tableau de bord ne charge que la page de journaux affichée (50 lignes par
défaut, jusqu’à 1 000). Les filtres, le tri chronologique et le décompte des
résultats sont calculés côté serveur SQLite ; changer de page ne conserve pas
les pages précédentes en mémoire. La carte, les indicateurs de trafic, les
statistiques d’utilisation et les courbes d’événements sont calculés en agrégats
serveur, sans charger les lignes brutes dans le navigateur. Le chargement initial
de la carte parcourt les journaux par lots de 1 000 entrées et actualise les
points après chaque lot. Le navigateur conserve au maximum 2 000 localisations ;
si la source en contient davantage, les comptes de la carte sont estimatifs afin
de maintenir cette limite mémoire.
Les statistiques des sets et cartes montrent les usages par semaine ou par mois.
Le nombre de jeux distincts est calculé à partir des compositions de cartes de
tous les royaumes, avec les signatures temporaires conservées dans SQLite plutôt
que dans la mémoire PHP. L’onglet « Événements dans le temps » détaille les types
d’événements et leur évolution temporelle.

Les réponses JSON de plus de 1 Ko sont gzipées si le navigateur annonce accepter
gzip ; vérifier `Content-Encoding: gzip` dans l’onglet Network pour confirmer.
Les temps de préparation, de migration, de requête, d’encodage et de compression
sont exposés dans `Server-Timing`.

Depuis la section des données brutes, les actions de maintenance permettent :

- de vider tous les événements de la base sélectionnée, sans supprimer le fichier
  SQLite ni sa table ;
- de supprimer les événements datés du jour choisi ou d’un jour antérieur ;
- de filtrer les journaux par `EventType`, identifiant de session exact et texte
  présent dans les paramètres JSON, puis de sélectionner et supprimer des lignes.
  La sélection est conservée entre les pages, effacée lorsque les critères
  changent et limitée à 1 000 lignes par opération. Le nombre de résultats par
  page peut être réglé entre 10 et 1 000 parmi les valeurs proposées.

La liste « Cartes et addons les plus utilisés » est distribuée en quatre colonnes
sur grand écran, deux sur écran moyen et une sur mobile.

Chaque suppression requiert une confirmation dans l’interface et le jeton
administrateur. Les événements reçus pendant une suppression restent soumis au
fonctionnement normal de l’écriture.

## Installer GeoIP sur IONOS

Le serveur charge le lecteur Composer depuis
`~/KingdomCreator/geoip-reader/vendor/autoload.php` et la base depuis
`~/KingdomCreator/geoip-reader/GeoLite2-Country.mmdb`. Ces chemins sont calculés
à partir de `~/KingdomCreator/Follow/server.php` ; ne placez pas `geoip-reader`
dans le répertoire web `Follow`.

1. Créer un compte MaxMind et télécharger la base **GeoLite2 Country** au format
   MMDB. Décompresser l’archive et transférer le fichier
   `GeoLite2-Country.mmdb` sur IONOS vers
   `~/KingdomCreator/geoip-reader/GeoLite2-Country.mmdb`, par SFTP ou SCP.
   La clé de licence MaxMind sert au téléchargement : ne l’ajoutez pas au dépôt
   ni à une page accessible publiquement.
2. Exécuter les commandes suivantes dans une session SSH **sur le serveur
   Linux IONOS**. Composer doit y être disponible et utiliser PHP 8.5 :

   ```sh
   cd ~/KingdomCreator
   mkdir -p geoip-reader
   cd geoip-reader
   composer require geoip2/geoip2
   ```

3. Vérifier que le lecteur et la base sont accessibles et que la base est
   valide :

   ```sh
   cd ~/KingdomCreator/geoip-reader
   test -r vendor/autoload.php
   test -r GeoLite2-Country.mmdb
   /usr/bin/php8.5-cli -r 'require "vendor/autoload.php"; new GeoIp2\Database\Reader("GeoLite2-Country.mmdb"); echo "GeoIP OK\n";'
   ```

Le script `bun run deploy:follow` ne transfère pas `geoip-reader` : cette
dépendance et la base doivent être installées séparément sur IONOS. Le processus
PHP doit pouvoir lire les deux fichiers et traverser leurs répertoires parents ;
n’utilisez pas de permissions globales telles que `777`. Si le lecteur ou la
base est absent, illisible ou invalide, l’API renvoie une erreur lors de la
réception d’événements. Téléchargez périodiquement une version à jour de la base
depuis MaxMind en respectant sa licence.

## Vérifier la réception des événements

Après avoir déployé la version de l’application qui contient le suivi, générer
un royaume puis consulter la liste des sources du
tableau de bord. Une source n’est créée qu’à la première écriture. Si elle reste
absente, ouvrir les outils développeur du navigateur, onglet **Réseau**, et
repérer le POST vers `server.php?source=...` :

- `200` : l’événement a été enregistré ;
- `400` ou `413` : consulter le corps de la réponse dans la console ;
- erreur réseau/CORS : vérifier les en-têtes et redirections de l’hébergement ;
- `500` : vérifier les journaux d’erreur PHP et la disponibilité de PDO SQLite
  ainsi que les droits d’écriture du répertoire `databases`.

Le navigateur affiche désormais le code HTTP et le corps d’une réponse en erreur.
Le serveur journalise le détail des erreurs SQLite/PDO dans les logs PHP de
l’hébergement.

## Données collectées

Les appels `trackEvent` et `trackError` enregistrent individuellement leur
`EventType` et leur catégorie (`event` ou `error`). Cela inclut les actions de
randomisation, les chargements depuis URL et `UPDATE_KINGDOM`. Ce dernier est
envoyé à chaque mise à jour, avec sa provenance. Ces événements rendent les
actions visibles dans les données brutes et le filtre `EventType`.
Deux envois consécutifs identiques (même catégorie, même `EventType` et mêmes
paramètres) ne produisent qu’une seule requête. L’ordre des propriétés d’un
objet n’affecte pas cette comparaison ; un autre événement entre les deux
réinitialise la déduplication. La signature est conservée dans le stockage de
session du navigateur afin que la protection survive au rechargement du même
onglet.
Les événements de randomisation incluent `selectedSets`, toutes les valeurs de
`randomizerSettings` et les détails utiles de `applicationSettings`.
`configurationAdjusted` vaut `true` uniquement si la taille personnalisée ou
les contraintes de randomisation sont activées ; `adjustedElements` identifie
les réglages concernés et les contraintes actives sont détaillées par set.
`configurationSimplified` signale séparément l’option « extensions possédées
uniquement ». Les options de `randomizerSettings` sont conservées pour les
statistiques, mais ne marquent pas la configuration comme ajustée. Le champ
`googleDriveConnected` indique uniquement si l’accès à Google Drive est vérifié
et son jeton encore valide au moment de la randomisation ; aucun jeton ni
identifiant de compte n’est transmis.
Chaque page suivie possède son événement dédié : `RANDOMIZER_VIEW`,
`RULEBOOK_VIEW`, `SEARCH_CARDS_VIEW`, `CARDS_VIEW`, `SETTINGS_VIEW`,
`HELP_VIEW` et `HISTORY_VIEW`. Les ouvertures et changements de set dans Boxes
utilisent `BOX_VIEW`, et ceux dans Sets utilisent `SET_VIEW`, chacun avec
l’identifiant du set consulté. `RULEBOOK_VIEW` inclut la langue affichée.
`PAGE_VIEW` n’est plus envoyé par la nouvelle version, mais reste pris en charge
pour afficher les anciennes données. Les anciens `PAGE_VIEW` de Boxes et Sets
sont reclassés dans les statistiques de sets consultés, pas dans les pages
générales. Aucun paramètre d’URL n’est envoyé.
Le clic sur le bouton d’ouverture des nouveautés est enregistré avec
`NEWS_MODAL_OPEN`.
Lorsque Google Drive est connecté, le clic sur « Joué » est enregistré avec
`MARK_KINGDOM_PLAYED`, et le clic sur « Rejouer » dans l’historique avec
`HISTORY_KINGDOM_REPLAY`. Ces événements ne contiennent pas l’identifiant du
royaume.

En complément, chaque royaume affiché ou modifié enregistre un événement
`KINGDOM_ACTIVITY` contenant :

- les identifiants des cartes, addons et cartes spéciales du royaume ;
- les extensions sélectionnées et l’option de tri ;
- les options du randomizer et les réglages généraux, dont contraintes et
  extensions possédées ;
- l’origine (`random`, `randomize_partial`, `edit`, `shared_url`,
  `shared_url_partial` ou `play_again`).

L’origine `play_again` correspond précisément à l’action « Play Again ».
Aucun hash, URL, nom, adresse e-mail, identifiant de compte ou donnée Google
n’est envoyé. Une mise à jour crée donc un `UPDATE_KINGDOM` et un
`KINGDOM_ACTIVITY`, en plus de l’événement de randomisation associé, le cas
échéant.

Les appels d’écriture ajoutent un `sessionId` unique par onglet, conservé pendant
les rechargements de cet onglet. Le tableau des données brutes permet de filtrer
et regrouper les événements par cet identifiant. Les anciennes lignes restent
consultables sans identifiant de session. La section analytique calcule également les pages et sets consultés, les langues
des règles, les clics suivis, les erreurs, les types de randomisation, leurs sets,
leurs réglages et l’état de connexion Google Drive. Les randomisations sont
comptées à partir des événements de catégorie `event`, et non des erreurs.
`KINGDOM_ACTIVITY` est désormais envoyé à chaque mise à jour de royaume avec sa
provenance et sa configuration. Pour les bases plus anciennes sans cet
événement, les cartes, royaumes récents et sets utilisés sont reconstruits à
partir des événements `UPDATE_KINGDOM` et des métadonnées de randomisation déjà
enregistrés ; la provenance historique reste indiquée comme inconnue.
Le contexte client est envoyé séparément des paramètres d’événement et stocké
dans la colonne `clientContext` de `RequestLog`. Il conserve les valeurs de
navigateur (`browser`) et d’appareil (`device`: `desktop`, `mobile` ou `tablet`),
ainsi que le pays (`country`) calculé par le serveur. Dans le tableau de bord,
seuls le pays et le type d’appareil sont affichés comme colonnes ; le contexte
complet reste dans la base et dans la réponse JSON protégée des journaux.

Le serveur utilise l’adresse `REMOTE_ADDR` fournie par PHP pour rechercher le
code pays dans la base locale GeoLite2 Country, placée dans
`../../geoip-reader/GeoLite2-Country.mmdb` par rapport au dossier de `server.php`.
Le lecteur Composer est chargé depuis `../../geoip-reader/vendor/autoload.php`.
Ces fichiers restent ainsi hors du dossier public. L’adresse IP n’est pas
ajoutée aux paramètres ni au contexte stocké. Si l’adresse n’est pas reconnue,
le code est `unknown`. Si les fichiers GeoIP sont manquants ou illisibles, l’API
renvoie une erreur et journalise le problème côté PHP.

L’attribution requise par GeoLite est affichée dans le tableau de bord : « This
product includes GeoLite Data created by MaxMind ». Les événements déjà
enregistrés avant l’activation de cette recherche peuvent encore contenir le
champ `ip` ; ils doivent être supprimés si vous ne souhaitez plus conserver ces
adresses. Les données brutes exposent l’ensemble des `EventType`.
