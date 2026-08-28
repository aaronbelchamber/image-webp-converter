# scripts

| Script | Category | Purpose | Added |
|---|---|---|---|
| [`test/run-php-matrix.cmd`](test/run-php-matrix.cmd) | test | Run the test suite against every PHP environment in wp-dev-playground's matrix, including the deliberately hostile ones this plugin has branches for. | 2026-08-27 |

## Notes

`run-php-matrix.cmd` needs [wp-dev-playground](../../wp-dev-playground) installed
and a container runtime running:

```
pip install -e E:\ab-code-projects\projects\wp-dev-playground
wpp doctor
```

It checks both and fails with the fix rather than a stack trace. Arguments are
passed through, so `run-php-matrix.cmd --json` gives machine-readable output.
