AWS_PROFILE  ?= default
AWS_REGION   ?= eu-west-3
ENV          ?= test
COMMIT_ID    ?= $(shell git rev-parse --short HEAD)$(shell git diff --quiet && git diff --cached --quiet || echo "-$(shell date +%s)")
SPIP_VERSION ?= $(shell cat spip/SPIP_VERSION)

ACCOUNT_ID   = $(shell aws sts get-caller-identity --profile $(AWS_PROFILE) --query Account --output text 2>/dev/null)
ECR_REGISTRY = $(ACCOUNT_ID).dkr.ecr.$(AWS_REGION).amazonaws.com
ECR_REPO     = $(ECR_REGISTRY)/spip-serverless
S3_BUCKET    = spip-serverless-$(ENV)-assets-$(ACCOUNT_ID)

.PHONY: help fetch-spip build build-local run-local push ecr-login \
        sync-assets deploy-static deploy-app deploy deploy-full download-adot lint

help:
	@echo "Usage:"
	@echo "  make fetch-spip     Download SPIP core into spip/src (local dev only; git-ignored)"
	@echo "  make build          Build the SPIP Lambda image (fetches SPIP core inside Docker)"
	@echo "  make push           Push the image to ECR"
	@echo "  make sync-assets    Extract static assets from the image and upload to S3"
	@echo "  make deploy-static  Apply the static Terraform stack (DSQL, S3, ECR, DynamoDB, SSM)"
	@echo "  make deploy-app     Apply the app Terraform stack (Lambda, API GW, CloudFront)"
	@echo "  make deploy         build + push + sync-assets + deploy-app"
	@echo "  make deploy-full    deploy-static + deploy (first-time bring-up)"
	@echo "  make run-local      Run SPIP locally with Apache on :8080"
	@echo "  make lint           php -l overlays + terraform fmt -check"
	@echo ""
	@echo "Variables: AWS_PROFILE=$(AWS_PROFILE) AWS_REGION=$(AWS_REGION) ENV=$(ENV) SPIP_VERSION=$(SPIP_VERSION)"

fetch-spip:
	spip/scripts/fetch-spip.sh $(SPIP_VERSION)

ecr-login:
	aws ecr get-login-password --region $(AWS_REGION) --profile $(AWS_PROFILE) | \
		docker login --username AWS --password-stdin $(ECR_REGISTRY)

build: download-adot
	docker build --platform linux/arm64 --provenance=false \
		--build-arg SPIP_VERSION=$(SPIP_VERSION) \
		-t spip-serverless:$(COMMIT_ID) -f spip/Dockerfile .

push: ecr-login
	docker tag spip-serverless:$(COMMIT_ID) $(ECR_REPO):$(COMMIT_ID)
	docker push $(ECR_REPO):$(COMMIT_ID)

sync-assets:
	@CID=$$(docker create spip-serverless:$(COMMIT_ID)) && \
	rm -rf /tmp/spip-assets && mkdir -p /tmp/spip-assets && \
	for d in squelettes-dist plugins-dist plugins prive; do docker cp $$CID:/var/task/$$d /tmp/spip-assets/$$d; done && \
	docker rm $$CID > /dev/null && \
	aws s3 sync /tmp/spip-assets/ s3://$(S3_BUCKET)/ --profile $(AWS_PROFILE) --exclude "*.php" && \
	rm -rf /tmp/spip-assets

deploy-static:
	cd iac/spip/static && AWS_PROFILE=$(AWS_PROFILE) terraform init -backend-config=var/$(ENV)/backend.tfbackend -reconfigure
	cd iac/spip/static && AWS_PROFILE=$(AWS_PROFILE) terraform apply -var-file=var/$(ENV)/values.tfvars -auto-approve

deploy-app:
	cd iac/spip/app && AWS_PROFILE=$(AWS_PROFILE) terraform init -backend-config=var/$(ENV)/backend.tfbackend -reconfigure
	cd iac/spip/app && AWS_PROFILE=$(AWS_PROFILE) terraform apply \
		-var-file=var/$(ENV)/values.tfvars \
		-var commit_id=$(COMMIT_ID) \
		-auto-approve

deploy: build push sync-assets deploy-app

deploy-full: deploy-static build push sync-assets deploy-app

build-local:
	docker build --target local --build-arg SPIP_VERSION=$(SPIP_VERSION) -t spip-local -f spip/Dockerfile .

run-local: build-local
	@eval $$(aws configure export-credentials --profile $(AWS_PROFILE) --format env) && \
	docker rm -f spip-local 2>/dev/null; \
	docker run -d --name spip-local -p 8080:80 \
		-e SPIP_DSQL_CLUSTER=$$(cd iac/spip/static && AWS_PROFILE=$(AWS_PROFILE) terraform output -raw dsql_endpoint 2>/dev/null) \
		-e AWS_ACCESS_KEY_ID=$$AWS_ACCESS_KEY_ID \
		-e AWS_SECRET_ACCESS_KEY=$$AWS_SECRET_ACCESS_KEY \
		-e AWS_SESSION_TOKEN=$$AWS_SESSION_TOKEN \
		-e AWS_REGION=$(AWS_REGION) \
		spip-local && \
	echo "SPIP running at http://localhost:8080"

lint:
	@echo "→ php -l on overlay PHP"
	@find spip/overlay spip/scripts spip/plugins -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null && echo "  ✓ no syntax errors"
	@echo "→ terraform fmt -check"
	@terraform fmt -check -recursive iac/

ADOT_LAYER_ARN = arn:aws:lambda:$(AWS_REGION):901920570463:layer:aws-otel-collector-arm64-ver-0-115-0:1

download-adot:
	@if [ ! -f spip/overlay/adot-collector ]; then \
		echo "Downloading ADOT Lambda extension..."; \
		URL=$$(aws lambda get-layer-version-by-arn --arn $(ADOT_LAYER_ARN) --region $(AWS_REGION) --profile $(AWS_PROFILE) --query 'Content.Location' --output text); \
		curl -sL "$$URL" -o /tmp/adot-layer.zip && \
		unzip -o /tmp/adot-layer.zip extensions/collector -d /tmp/adot && \
		mv /tmp/adot/extensions/collector spip/overlay/adot-collector && \
		rm -rf /tmp/adot /tmp/adot-layer.zip && \
		echo "Done: spip/overlay/adot-collector"; \
	else echo "spip/overlay/adot-collector already exists"; fi
