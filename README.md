# Radianz pour Laravel

Ce paquet transmet les erreurs du journal Laravel à l’Error Inbox de Radianz. La découverte automatique branche le connecteur sur les événements du journal. Une application qui utilise le traitement standard de Laravel peut ainsi transmettre les exceptions signalées par `report()` et les appels à `Log::error()` avec la même installation.

## Compatibilité ciblée au 2 novembre 2026

| Laravel | Branches PHP à tester à chaque livraison | Correctifs de sécurité Laravel jusqu’au |
|---|---|---|
| 12 | 8.2, 8.3, 8.4, 8.5 | 24 février 2027 |
| 13 | 8.3, 8.4, 8.5 | 17 mars 2028 |

Utiliser le dernier correctif disponible de chaque branche. La politique du projet est de couvrir l’intersection des branches stables maintenues par PHP et Laravel, puis de qualifier chaque nouvelle branche avant d’élargir les contraintes Composer. Réexaminer PHP 8.2 le 31 décembre 2026. Communiquer les retraits avant une nouvelle version majeure du paquet. [Calendrier PHP](https://www.php.net/supported-versions.php) · [Calendrier Laravel](https://laravel.com/docs/13.x/releases)

## Installation

Depuis la racine de votre application Laravel, ajouter le connecteur avec Composer.

```sh
composer require radianz/laravel
```

Versionner les fichiers `composer.json` et `composer.lock` de l’application et utiliser `composer install --no-dev` au déploiement.

Configurer dans les secrets de déploiement le couple `RADIANZ_CLIENT_ID` et `RADIANZ_API_KEY` d’un jeton de logs de l’application Radianz. Le paquet reprend `APP_NAME` et `APP_ENV`. On peut fournir la version déployée via `RADIANZ_RELEASE` et sélectionner l’URL HTTPS complète du service d’ingestion de QA via `RADIANZ_ENDPOINT`. La destination par défaut est `https://radianz.io/api/v1/logs`.

Reconstruire le cache de configuration Laravel et redémarrer les workers persistants après toute modification. Exécuter `php artisan radianz:test`. La commande signale une exception via `report()` et affiche l’identifiant uniquement si le journal l’a transmise et que l’API a confirmé l’ingestion. Si le seuil du canal filtre les erreurs ou si Laravel ignore l’exception, la commande échoue avec un diagnostic. Retrouver ensuite `RadianzTestException` dans l’Inbox de l’application.

## Comportement de collecte

- Les niveaux `error`, `critical`, `alert` et `emergency` déclenchent l’envoi. Le connecteur écoute le journal Laravel dans les processus web, Artisan et workers. Les exceptions ignorées par Laravel ou interceptées par un gestionnaire qui court-circuite entièrement la journalisation sont hors de ce parcours.
- La collecte transmet le type, les causes, les emplacements et les méthodes. Le regroupement utilise le fichier et la méthode d’origine, tandis que les lignes restent visibles dans les détails. Deux erreurs du même type au sein d’une même méthode peuvent donc partager un incident. Les arguments, le contexte libre et le texte des messages sont exclus par défaut. Activer `RADIANZ_INCLUDE_MESSAGES=true` uniquement si l’application retire les données confidentielles avant journalisation. `off`, `no`, `false` et les valeurs inconnues laissent les messages désactivés.
- Pour les logs manuels, utiliser un gabarit stable comme `Log::error('Paiement {id} refusé', ['id' => $id])`. Quand le texte est masqué, le paquet normalise les identifiants numériques et UUID, puis calcule une empreinte avec `APP_KEY`. La rotation du jeton Radianz conserve cette empreinte. En l’absence de clé applicative, l’emplacement dans le code sert de repli.
- L’envoi HTTPS est synchrone et limité à deux secondes pour la connexion et trois secondes au total par tentative. Après une panne réseau, un refus d’accès, une limitation du débit ou une erreur serveur, l’instance suspend les envois ordinaires pendant 60 secondes. Les occurrences de cette période sont perdues. La commande Artisan permet de vérifier immédiatement le rétablissement.
