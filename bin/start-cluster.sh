#!/bin/sh
set -eu
# Dedicated disposable test container, one network namespace so loopback announcements work on macOS too.
docker run --detach --name model-cache-test-cluster --publish 127.0.0.1:16380-16382:16380-16382 "${MC_TEST_REDIS_IMAGE:-redis:8}" sh -c 'for port in 16380 16381 16382; do mkdir -p /tmp/redis-$port; redis-server --port $port --bind 0.0.0.0 --protected-mode no --cluster-enabled yes --cluster-config-file /tmp/redis-$port/nodes.conf --cluster-node-timeout 5000 --cluster-announce-ip 127.0.0.1 --cluster-announce-port $port --cluster-announce-bus-port $((port + 10000)) --daemonize yes; done; exec sleep infinity'
docker exec model-cache-test-cluster redis-cli --cluster create 127.0.0.1:16380 127.0.0.1:16381 127.0.0.1:16382 --cluster-replicas 0 --cluster-yes
# Slot assignment can finish before gossip has enabled writes on every master.
for attempt in $(seq 1 150); do
    ready=true
    for port in 16380 16381 16382; do
        state=$(docker exec model-cache-test-cluster redis-cli -p "$port" cluster info)
        case "$state" in
            *cluster_state:ok*) ;;
            *) ready=false ;;
        esac
    done
    if [ "$ready" = true ]; then exit 0; fi
    sleep 0.2
done
echo 'Redis Cluster did not become ready on all masters' >&2
exit 1
