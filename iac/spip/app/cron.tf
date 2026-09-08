# EventBridge rule that runs SPIP's cron/queue every 5 minutes.
#
# On Lambda, SPIP's queue is disabled on normal web requests (see prepend.php,
# _DEBUG_BLOCK_QUEUE) so page loads stay fast. This rule invokes the Lambda with a
# synthetic API Gateway event for `/spip.php?action=cron`, which is the one path that
# runs the queue — so scheduled jobs fire deterministically, independent of traffic.
resource "aws_cloudwatch_event_rule" "spip_cron" {
  name                = "${local.app_name}-cron"
  schedule_expression = "rate(5 minutes)"
  state               = "ENABLED"
}

resource "aws_cloudwatch_event_target" "spip_cron" {
  rule      = aws_cloudwatch_event_rule.spip_cron.name
  target_id = "spip-cron"
  arn       = aws_lambda_alias.spip_live.arn

  input = jsonencode({
    version               = "2.0"
    routeKey              = "GET /spip.php"
    rawPath               = "/spip.php"
    rawQueryString        = "action=cron"
    headers               = { "x-spip-cron" = "1" }
    queryStringParameters = { action = "cron" }
    requestContext = {
      http = { method = "GET", path = "/spip.php" }
    }
    isBase64Encoded = false
  })
}

resource "aws_lambda_permission" "eventbridge_cron" {
  statement_id  = "AllowEventBridgeCron"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.spip.function_name
  qualifier     = aws_lambda_alias.spip_live.name
  principal     = "events.amazonaws.com"
  source_arn    = aws_cloudwatch_event_rule.spip_cron.arn
}
