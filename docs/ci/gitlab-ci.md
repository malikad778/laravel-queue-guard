# GitLab CI Integration

Queue Guard includes native support for GitLab Code Quality artifacts.

```yaml
queue-guard:
  image: php:8.2
  stage: test
  before_script:
    - apt-get update && apt-get install -y git unzip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install --prefer-dist --no-progress
  script:
    - vendor/bin/queue-guard analyse app --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```
