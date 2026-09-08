# Aurora DSQL cluster — the single source of truth for SPIP content.
# Protect + back up environments that hold real data; dev/test stay disposable.
# deletion_protection guards against accidental teardown; the backup=daily tag opts
# the cluster into a tag-based AWS Backup plan (define the plan separately).
resource "aws_dsql_cluster" "spip" {
  deletion_protection_enabled = contains(["prep", "prod"], var.env)

  tags = contains(["prep", "prod"], var.env) ? {
    Name   = local.app_name
    backup = "daily"
    } : {
    Name = local.app_name
  }
}
