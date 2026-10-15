# Arco secrets

Parsing of the secret references used by [Arco](https://github.com/multi-api/arco):

- `arco-ssm:<parameter-name>` — an AWS SSM Parameter Store value (SecureString supported)
- `arco-secret:<secret-id>` — an AWS Secrets Manager string value
- `arco-secret:<secret-id>#<json-key>` — one key of a JSON secret

Arco's runtime resolves these references when the Lambda function starts; this package is shared
by the CLI (validation, IAM scoping) and is not needed in your app directly.

Documentation, issues and pull requests: [multi-api/arco](https://github.com/multi-api/arco).
This repository is a read-only split.

## License

MIT
