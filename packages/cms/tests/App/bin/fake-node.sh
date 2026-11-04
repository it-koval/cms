#!/bin/sh
# A stand-in for node in the tests of NodeCssExtractor: reads the job from stdin and answers as ITK_FAKE_NODE says.
# The job is printed to stderr, so a test can see what PHP sent.
if [ "$1" = "--version" ]; then
    echo "${ITK_FAKE_NODE_VERSION:-v22.12.0}"
    exit 0
fi
job=$(cat)
printf '%s\n' "$job" >&2
case "$ITK_FAKE_NODE" in
    ok)
        printf '%s\n' '{"id":"a","ok":true,"css":["x{a:1}","x{a:2}"]}' 'not json at all' '{"id":"b","ok":false,"error":"page broke"}'
        ;;
    hang)
        printf '%s\n' '{"id":"a","ok":true,"css":["x{a:1}","x{a:2}"]}'
        exec sleep 60
        ;;
    unavailable)
        echo "not installed: crittr is not in /site/node_modules (npm install crittr)" >&2
        exit 69
        ;;
    *)
        exit 70
        ;;
esac
