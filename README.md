# MAT-TECH PC — catalogue automatique sur GitHub Actions

Ce dépôt contient uniquement le programme et des observations de fiches marchandes publiques. Aucun compte client, mot de passe OVH ou accès administrateur n'est nécessaire.

## Installation

1. Créer un dépôt **public** nommé `mat-tech-catalogue`, sur le plan GitHub Free, avec une branche `main` et un README.
2. Déposer les dossiers `collector`, `scripts`, `data`, `public`, `site` et `tests` à la racine du dépôt.
3. Créer `.github/workflows/catalogue.yml` et y copier exactement le fichier `workflow-a-copier.yml` fourni séparément dans le kit.
4. Dans **Actions > Catalogue MAT-TECH > Run workflow**, lancer une première exécution.
5. Ouvrir son résultat. Le résumé indique les vraies lectures, les offres validées et les éventuels refus des boutiques.
6. Télécharger l'artifact `site-a-deposer-sur-OVH` depuis le bas de cette exécution, l'extraire, et déposer son contenu dans un nouveau dossier `catalogue-auto` de son site via Cyberduck. Le fichier `index.html` doit être directement dans ce dossier.
7. Ouvrir `https://mat-tech-pc.fr/catalogue-auto/` pour vérifier l'installation avant de modifier un lien du site principal.

Le lien du dépôt est écrit automatiquement dans `site/config.js` pendant le lancement. Il ne faut pas déposer le dossier `site` du kit initial sur OVH avant ce premier lancement : son lien n'est pas encore configuré. Il faut télécharger l'artifact du lancement réussi. S'il a expiré après un jour, relancer manuellement le workflow.

## Fonctionnement et limites concrètes

- Un passage est programmé chaque heure à la minute 23, en UTC. GitHub peut retarder ou manquer un passage ; il ne s'agit pas d'une garantie de temps réel.
- Le programme traite au maximum 80 adresses ou environ quatre minutes par passage, sans tenter de contourner un CAPTCHA ou un refus d'accès. Cette durée est une limite souple pour la collecte, le job entier est limité à huit minutes.
- Les tâches de découverte, les contrôles des produits, l'historique, et les changements sont conservés dans le dépôt après chaque passage réussi. Le catalogue grossit progressivement ; il ne contient pas toutes les références historiques depuis AM4.
- Les quatre connecteurs actuels concernent ALTERNATE, PcComponentes, LDLC et Materiel.net. Amazon n'est pas connecté dans ce kit.
- Un prix provient d'une offre exacte : référence, boutique et vendeur, matériel neuf, stock déclaré, URL produit et date propre à l'offre. Une somme est stockée en centimes entiers. Aucune réduction n'est inventée.
- Le site recharge la publication environ toutes les 90 secondes lorsqu'il est visible. La diffusion du fichier GitHub peut avoir quelques minutes de cache.
- Un ancien prix est masqué après trois heures même si la page reste ouverte. Un passage horaire ne signifie pas que chaque référence a été relue dans l'heure. Les références sans contrôle récent restent consultables avec le lien et la mention « Prix à actualiser ».
- Les baisses et les hausses sont enregistrées dans Activité après de véritables observations successives. **Les alertes de prix par e-mail ne sont pas connectées**. Les notifications d'échec GitHub sont une fonction différente.
- Un statut vert du workflow signifie que le programme s'est exécuté, pas que toutes les boutiques ont accepté les lectures. Consulter le résumé et le nombre d'offres validées.
- Le code et les observations seront publics. Ne jamais ajouter d'autres fichiers du site, d'anciens guides avec mots de passe, ni `config.php` provenant de l'ancienne version.

## Coût et disponibilité du service

Ce workflow utilise le runner Linux standard `ubuntu-24.04` dans un dépôt public. Les minutes correspondantes sont gratuites selon les conditions GitHub consultées le 17 octobre 2026. L'artifact d'installation n'est créé que lors d'un lancement manuel, avec une conservation d'un jour ; les exécutions horaires n'empilent pas d'artifacts. Ne pas changer le dépôt en privé ou le runner sans revoir les quotas.

Ne pas activer d'abonnement ou de runner payant pour ce kit. Si le compte possède déjà une carte bancaire, vérifier les budgets et le blocage des dépassements dans la facturation. Il n'est pas nécessaire de souscrire un hébergement supplémentaire chez OVH : l'hébergement existant sert les fichiers statiques du catalogue.

Les workflows planifiés d'un dépôt public peuvent être désactivés après 60 jours sans activité. En cas d'arrêt, vérifier l'onglet Actions, réactiver le workflow et lancer un passage manuel. Cette offre gratuite ne constitue pas une garantie de disponibilité permanente.

## Vérification locale

PHP 8.1+ avec cURL, DOM et iconv, Node.js pour les tests de rendu en VM :

```sh
php tests/export.php
php scripts/collect.php --seconds=35 --jobs=4
node tests/site.cjs
```

`scripts/export.php` reconstruit les fichiers publics depuis les observations déjà enregistrées, sans effectuer de nouvelle lecture. Il ne change jamais la date d'un relevé.

## Sources officielles

- [Tarification GitHub Actions](https://docs.github.com/en/billing/concepts/product-billing/github-actions)
- [Créer un dépôt](https://docs.github.com/en/repositories/creating-and-managing-repositories/creating-a-new-repository)
- [Déposer des fichiers](https://docs.github.com/en/repositories/working-with-files/managing-files/adding-a-file-to-a-repository)
- [Lancer manuellement](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow)
- [Télécharger l'artifact](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/download-workflow-artifacts)
- [Planification et interruptions](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule)
- [Budgets](https://docs.github.com/en/billing/how-tos/set-up-budgets)
