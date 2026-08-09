#!/usr/bin/env bash
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sS --retry 3 -L "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [[ "$WP_VERSION" == "latest" ]]; then
	WP_TESTS_TAG=trunk
	WP_TESTS_BASE=https://develop.svn.wordpress.org/trunk
	WP_ARCHIVE_URL=https://wordpress.org/latest.tar.gz
else
	WP_TESTS_TAG="$WP_VERSION"
	WP_TESTS_BASE="https://develop.svn.wordpress.org/tags/${WP_TESTS_TAG}"
	WP_ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

if [[ ! -f "$WP_CORE_DIR/wp-settings.php" ]]; then
	tmp_archive=$(mktemp)
	download "$WP_ARCHIVE_URL" "$tmp_archive"
	mkdir -p "$WP_CORE_DIR"
	tar --strip-components=1 -xzf "$tmp_archive" -C "$WP_CORE_DIR"
	rm -f "$tmp_archive"
fi

if [[ ! -f "$WP_TESTS_DIR/includes/functions.php" ]]; then
	mkdir -p "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
	svn export --quiet "${WP_TESTS_BASE}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export --quiet "${WP_TESTS_BASE}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
fi

download "${WP_TESTS_BASE}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"

mysqladmin --user="$DB_USER" --password="$DB_PASS" --host="${DB_HOST%%:*}" create "$DB_NAME" >/dev/null 2>&1 || true
