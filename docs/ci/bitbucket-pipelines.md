# Bitbucket Pipelines Integration

Queue Guard supports Checkstyle and JUnit XML reporting for Bitbucket Pipelines.

```yaml
image: php:8.2

pipelines:
  default:
    - step:
        name: Queue Guard Static Analysis
        caches:
          - composer
        script:
          - apt-get update && apt-get install -y git unzip
          - composer install --prefer-dist --no-progress
          - vendor/bin/queue-guard analyse app --format=checkstyle > checkstyle.xml
```
