# Transactional email via Amazon SES (optional)

[Français](../fr/email-ses.md) · **English**

SPIP's native mail uses PHP `mail()`/sendmail, which does **not** work on Lambda/Bref
(no local MTA). The `ses_mail` plugin routes SPIP's transactional emails (password reset,
notifications, forms…) through **Amazon SES** instead.

It is **optional and self-disabling**: with no SES configuration the plugin falls back to
native `mail()`, so nothing changes until you opt in.

## How it works

- `spip/plugins/ses_mail/inc/envoyer_mail.php` overrides SPIP's `inc_envoyer_mail` (the
  standard SPIP function-surcharge mechanism), so every `envoyer_mail(...)` call goes
  through it transparently.
- If `SES_FROM` (and `SES_REGION`) are set → it calls `SES:SendEmail` via the AWS SDK
  (already bundled). Otherwise → native `mail()` fallback.
- `From` is `SES_FROM` (which must be a verified SES identity); a caller-supplied From is
  passed as `Reply-To`. Text + HTML bodies, `cc`/`bcc`, and the site charset are honoured.
- Outcomes are logged to the `ses` SPIP log channel (→ CloudWatch via `logs_stderr`).

## Enable it

1. **Verify an SES identity** (a domain or a single email) in the SES region for this
   account. New SES accounts are in the **sandbox**: you can only send to *verified*
   recipients until you request production access.
2. Set the From address in `iac/spip/app/var/<env>/values.tfvars`:
   ```hcl
   ses_from_email = "My Site <noreply@example.com>"
   ```
   This is all it takes — Terraform then:
   - attaches the `ses:SendEmail` / `ses:SendRawEmail` IAM policy to the Lambda role
     (`aws_iam_role_policy.ses_send`, created only when `ses_from_email != ""`), and
   - injects `SES_FROM` + `SES_REGION` (= the stack region) into the Lambda env.
3. `make deploy ENV=<env>` (or push to `main` for the CI). SPIP mail now goes via SES.

## Region

SES is not available in every region. `SES_REGION` defaults to the stack region
(`AWS_REGION`); if SES is not offered there, verify your identity in a supported region
and set `SES_REGION` accordingly (a small stack tweak).

## Disable it

Leave `ses_from_email` empty (the default): the plugin stays inert and falls back to
native `mail()` (which is a no-op on Lambda — i.e. no transactional email). To remove the
code path entirely, drop the `COPY spip/plugins/ses_mail/ …` line from `spip/Dockerfile`.

## Sandbox → production

To send to arbitrary recipients, request SES production access for the account/region
(AWS console → SES → Account dashboard). Until then, only verified identities receive mail.
