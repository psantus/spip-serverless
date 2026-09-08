# Secrets Management (SSM Parameter Store)

**Français** · [English](../en/secrets.md)

## Architecture

```
SSM Parameter Store (SecureString) → Bref secrets-loader → Lambda env var → prepend.php → /tmp/spip/etc/cles.php
```

Les secrets sont stockés dans AWS SSM Parameter Store sous forme de SecureString. Le package `secrets-loader` de Bref résout les variables d'environnement préfixées par `bref-ssm:/path` au démarrage à froid de la Lambda, en les remplaçant par la valeur réelle du secret.

## Secrets actuels

| Chemin du paramètre | Variable d'env. | Rôle |
|---|---|---|
| `/spip-serverless/{env}/spip/cles` | `SPIP_CLES` | Clé secrète du site SPIP (utilisée pour la signature des sessions, les jetons CSRF) |

## Fonctionnement

1. **Terraform** crée le paramètre SSM (`iac/spip/static/ssm.tf`)
2. **La variable d'env. Lambda** est définie à `bref-ssm:/spip-serverless/test/spip/cles` (`iac/spip/app/locals.tf`)
3. **Le runtime Bref** résout le préfixe `bref-ssm:` au démarrage à froid → remplace la variable d'env. par la valeur réelle
4. **prepend.php** lit `getenv('SPIP_CLES')` et écrit `/tmp/spip/etc/cles.php`

## Ressources Terraform

### Stack static (`iac/spip/static/ssm.tf`)
```hcl
resource "aws_ssm_parameter" "spip_cles" {
  name  = "/spip-serverless/${var.env}/spip/cles"
  type  = "SecureString"
  value = "CHANGE_ME_AFTER_CREATION"
  lifecycle { ignore_changes = [value] }
}
```

### Stack app (`iac/spip/app/locals.tf`)
```hcl
SPIP_CLES = "bref-ssm:${local.spip_cles_ssm}"
```

### IAM (`iac/spip/app/lambda.tf`)
```hcl
Action   = ["ssm:GetParameter", "ssm:GetParameters"]
Resource = "arn:aws:ssm:*:*:parameter/spip-serverless/${var.env}/*"
```

## Définir la valeur d'un secret

Une fois que terraform a créé le paramètre (avec une valeur de remplacement), définissez la valeur réelle :
```bash
aws ssm put-parameter \
  --name "/spip-serverless/test/spip/cles" \
  --type SecureString \
  --value '{"secret_du_site":"your-base64-secret-here"}' \
  --overwrite --region us-east-1 --profile <your-profile>
```

## Ajouter un nouveau secret

1. Ajoutez le paramètre SSM dans `iac/spip/static/ssm.tf`
2. Ajoutez une sortie (output) pour le nom du paramètre
3. Ajoutez la variable d'env. `bref-ssm:` dans `iac/spip/app/locals.tf`
4. Lisez-la dans `prepend.php` via `getenv('YOUR_VAR')`
5. Déployez d'abord la stack static, définissez la valeur, puis déployez la stack app

## Dépendances

- `bref/secrets-loader:^1` — package composer qui résout `bref-ssm:` à l'exécution
- Le rôle IAM Lambda a besoin de la permission `ssm:GetParameter`

## Sécurité

- Les secrets sont chiffrés au repos (SSM SecureString utilise AWS KMS)
- N'apparaissent jamais dans l'image Docker ni dans git
- Résolus une seule fois au démarrage à froid, mis en cache en mémoire pour la durée de vie de l'instance
- `/tmp/spip/etc/cles.php` est éphémère (perdu à la fin de l'instance)

## Dépannage

- **« bref/secrets-loader package is required »** — ajoutez `bref/secrets-loader:^1` au composer require dans le Dockerfile
- **« Access denied » sur SSM** — vérifiez que le rôle IAM Lambda dispose de `ssm:GetParameter` pour l'ARN du paramètre
- **Le secret ne se met pas à jour** — Lambda met en cache les variables d'env. par instance. Forcez un démarrage à froid en déployant une nouvelle image.
