# Emails transactionnels via Amazon SES (optionnel)

**Français** · [English](../en/email-ses.md)

Le mail natif de SPIP utilise `mail()`/sendmail de PHP, qui **ne fonctionne pas** sur
Lambda/Bref (pas de MTA local). Le plugin `ses_mail` route les emails transactionnels de
SPIP (réinitialisation de mot de passe, notifications, formulaires…) via **Amazon SES**.

Il est **optionnel et auto-désactivé** : sans configuration SES, le plugin retombe sur
`mail()` natif — rien ne change tant que tu ne l'actives pas.

## Comment ça marche

- `spip/plugins/ses_mail/inc/envoyer_mail.php` surcharge `inc_envoyer_mail` (le mécanisme
  standard de surcharge de fonction SPIP) : chaque appel `envoyer_mail(...)` y passe
  de façon transparente.
- Si `SES_FROM` (et `SES_REGION`) sont définis → appel `SES:SendEmail` via le SDK AWS
  (déjà embarqué). Sinon → repli sur `mail()` natif.
- Le `From` est `SES_FROM` (qui doit être une identité SES vérifiée) ; un From fourni par
  l'appelant devient le `Reply-To`. Corps texte + HTML, `cc`/`bcc` et le charset du site
  sont pris en compte.
- Les résultats sont journalisés sur le canal SPIP `ses` (→ CloudWatch via `logs_stderr`).

## Activer

1. **Vérifie une identité SES** (un domaine ou une adresse) dans la région SES de ce
   compte. Un compte SES neuf est en **sandbox** : tu ne peux envoyer qu'à des
   destinataires *vérifiés* tant que tu n'as pas demandé l'accès production.
2. Renseigne l'adresse d'expéditeur dans `iac/spip/app/var/<env>/values.tfvars` :
   ```hcl
   ses_from_email = "Mon site <noreply@example.com>"
   ```
   C'est tout — Terraform :
   - attache la policy IAM `ses:SendEmail` / `ses:SendRawEmail` au rôle du Lambda
     (`aws_iam_role_policy.ses_send`, créée seulement si `ses_from_email != ""`), et
   - injecte `SES_FROM` + `SES_REGION` (= la région de la stack) dans l'env du Lambda.
3. `make deploy ENV=<env>` (ou push sur `main` pour la CI). Le mail SPIP passe alors par SES.

## Région

SES n'est pas disponible dans toutes les régions. `SES_REGION` vaut par défaut la région
de la stack (`AWS_REGION`) ; si SES n'y est pas proposé, vérifie ton identité dans une
région supportée et positionne `SES_REGION` en conséquence (petit ajustement de stack).

## Désactiver

Laisse `ses_from_email` vide (défaut) : le plugin reste inerte et retombe sur `mail()`
natif (qui est un no-op sur Lambda — donc pas d'email transactionnel). Pour retirer
complètement le chemin de code, supprime la ligne `COPY spip/plugins/ses_mail/ …` de
`spip/Dockerfile`.

## Sandbox → production

Pour envoyer à n'importe quel destinataire, demande l'accès production SES pour le
compte/la région (console AWS → SES → Tableau de bord du compte). Avant ça, seules les
identités vérifiées reçoivent les emails.
