# Logs vers STDERR

## Présentation

Ce plugin modifie le comportement standard de SPIP en matière de journalisation.

Par défaut, SPIP écrit ses logs dans des fichiers situés dans le répertoire `tmp/log/`.  
Ce fonctionnement est adapté à des environnements classiques, mais devient moins pertinent dans des architectures modernes (Docker, CI/CD, orchestration, etc.).

Dans ces contextes, la bonne pratique consiste à envoyer les logs vers les flux système (`stdout` / `stderr`) afin qu’ils puissent être collectés, centralisés et analysés par des outils dédiés (logs Docker, ELK, Loki, Grafana…).

**Ce plugin redirige donc les appels à `spip_log()` vers `stderr` via `error_log()`, sans écriture dans `tmp/log/`.**

---

## Objectif

- Adapter SPIP aux environnements conteneurisés
- Centraliser les logs applicatifs avec les logs système
- Simplifier l’exploitation (pas de gestion de fichiers de logs côté SPIP)
- Conserver un format de log proche de celui de SPIP (PID, contexte public/privé, debug…)

---

## Installation

1. Installer le plugin dans le répertoire `plugins/`
2. Activer le plugin depuis l’espace privé de SPIP

Aucune configuration supplémentaire n’est nécessaire côté SPIP.

---

## Configuration requise

Pour que le plugin fonctionne correctement, PHP doit être configuré pour envoyer ses logs vers `stderr`.

Exemple de configuration :

```ini
log_errors = On
error_log = /dev/stderr
````

Dans un environnement Docker, cette configuration permet de récupérer les logs via :

```bash
docker logs <container>
```

---

## Utilisation

Une fois le plugin activé, tous les appels à `spip_log('Mon message')` seront envoyés vers `stderr` au lieu d’être écrits dans des fichiers dans `tmp/log/`.

Le format des logs conserve les éléments utiles :

* PID du processus
* contexte public (`Pub`) ou privé (`Pri`)
* informations de debug si `_LOG_FILELINE` est activé
* nom du canal de log

---

## Exemple

Un appel `spip_log('Connexion MySQL OK', 'mysql');` donnera un log de type :

```
[spip][mysql][pri][pid:123] ecrire/req/mysql.php:L91:req_mysql_dist() Connexion MySQL OK
```

(Le format exact peut varier selon la configuration.)

---

## Limitations

* Les logs ne sont plus disponibles dans `tmp/log/`
* Les outils SPIP classiques de consultation des logs ne fonctionneront plus
* La rotation des logs n’est plus gérée par SPIP (elle est déléguée à l’infrastructure)

---

## Cas d’usage

Ce plugin est particulièrement utile dans les environnements suivants :

* Docker
* Kubernetes
* CI/CD (GitLab CI, GitHub Actions…)
* plateformes avec centralisation des logs

---

## Remarques

Ce plugin modifie volontairement un comportement historique de SPIP. Il est recommandé de l’utiliser uniquement si votre environnement d’hébergement est adapté à ce mode de fonctionnement.

---

## Licence

GNU/GPL

