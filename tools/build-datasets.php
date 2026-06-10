<?php
/**
 * Build filtered conservation area and Article 4 Direction GeoJSON files.
 *
 * This is a one-off developer tool, not part of the runtime plugin. It takes
 * the national Planning Data datasets, keeps only the features that fall
 * inside a bounding box (the service region), slims the properties, and writes
 * two files the plugin loads automatically:
 *
 *   data/conservation-areas.json
 *   data/article-4-areas.json
 *
 * Run it from the plugin root with PHP CLI:
 *
 *   php tools/build-datasets.php
 *
 * The national files are large, so this script can need a lot of memory. It
 * raises the limit to 2G; lower or raise to suit your machine.
 *
 * Two ways to supply the source data:
 *
 *   1. Let the script download it (needs outbound access to
 *      planning.data.gov.uk):
 *        php tools/build-datasets.php
 *
 *   2. Download the two files in a browser first, then point the script at the
 *      local copies (handy when the server cannot reach the dataset host):
 *        php tools/build-datasets.php \
 *          --conservation=/path/to/conservation-area.geojson \
 *          --article4=/path/to/article-4-direction-area.geojson
 *
 * Options:
 *   --conservation=URL_OR_PATH  Source for conservation areas.
 *   --article4=URL_OR_PATH      Source for Article 4 Direction areas.
 *   --bbox=minLon,minLat,maxLon,maxLat  Bounding box (default: HAM/SUR/BER).
 *
 * Dataset homepages:
 *   https://www.planning.data.gov.uk/dataset/conservation-area
 *   https://www.planning.data.gov.uk/dataset/article-4-direction-area
 *
 * @package Conservation_Area_Checker
 */

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

ini_set( 'memory_limit', '2G' );

// Defaults.
$defaults = array(
	'conservation' => 'https://www.planning.data.gov.uk/dataset/conservation-area.geojson',
	'article4'     => 'https://www.planning.data.gov.uk/dataset/article-4-direction-area.geojson',
	// Generous bounding box around Hampshire, Surrey, and Berkshire.
	'bbox'         => '-1.95,50.70,0.10,51.75',
);

$args = cac_parse_args( $GLOBALS['argv'], $defaults );

$bbox = array_map( 'floatval', explode( ',', $args['bbox'] ) );
if ( count( $bbox ) !== 4 ) {
	fwrite( STDERR, "Invalid --bbox. Expected minLon,minLat,maxLon,maxLat.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );

cac_build(
	$args['conservation'],
	$root . '/data/conservation-areas.json',
	$bbox,
	'conservation areas'
);

cac_build(
	$args['article4'],
	$root . '/data/article-4-areas.json',
	$bbox,
	'Article 4 Direction areas'
);

echo "Done.\n";

/**
 * Parse simple --key=value CLI arguments over a set of defaults.
 *
 * @param array $argv     Raw argv.
 * @param array $defaults Default values.
 * @return array
 */
function cac_parse_args( $argv, $defaults ) {
	$out = $defaults;
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( preg_match( '/^--([a-z0-9_]+)=(.*)$/i', $arg, $m ) ) {
			$out[ $m[1] ] = $m[2];
		}
	}
	return $out;
}

/**
 * Download or read, filter, slim, and write one dataset.
 *
 * @param string $source   URL or local path to the source GeoJSON.
 * @param string $out_path Destination file path.
 * @param array  $bbox     [minLon, minLat, maxLon, maxLat].
 * @param string $label    Human label for progress output.
 */
function cac_build( $source, $out_path, $bbox, $label ) {
	echo "Building {$label} from {$source}\n";

	$raw = cac_load( $source );
	if ( null === $raw ) {
		fwrite( STDERR, "  Could not read source for {$label}. Skipping.\n" );
		return;
	}

	$data = json_decode( $raw, true );
	unset( $raw );
	if ( ! is_array( $data ) || empty( $data['features'] ) ) {
		fwrite( STDERR, "  Source for {$label} is not a GeoJSON FeatureCollection. Skipping.\n" );
		return;
	}

	$kept = array();
	foreach ( $data['features'] as $feature ) {
		if ( empty( $feature['geometry']['coordinates'] ) ) {
			continue;
		}
		if ( ! cac_coords_in_bbox( $feature['geometry']['coordinates'], $bbox ) ) {
			continue;
		}

		$props = isset( $feature['properties'] ) && is_array( $feature['properties'] ) ? $feature['properties'] : array();
		$kept[] = array(
			'type'       => 'Feature',
			'properties' => array(
				'name'      => isset( $props['name'] ) ? $props['name'] : '',
				'reference' => isset( $props['reference'] ) ? $props['reference'] : '',
			),
			'geometry'   => $feature['geometry'],
		);
	}

	$collection = array(
		'type'     => 'FeatureCollection',
		'name'     => basename( $out_path, '.json' ),
		'features' => $kept,
	);

	file_put_contents( $out_path, json_encode( $collection, JSON_UNESCAPED_SLASHES ) );
	echo '  Wrote ' . count( $kept ) . " features to {$out_path}\n";
}

/**
 * Load source content from a URL or a local file.
 *
 * @param string $source URL or path.
 * @return string|null
 */
function cac_load( $source ) {
	if ( preg_match( '#^https?://#i', $source ) ) {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'follow_location' => 1,
					'timeout'       => 600,
					'header'        => "User-Agent: ConservationAreaChecker/1.0\r\nAccept: application/geo+json,application/json\r\n",
				),
			)
		);
		$body = @file_get_contents( $source, false, $context );
		return ( false === $body ) ? null : $body;
	}

	if ( is_readable( $source ) ) {
		$body = file_get_contents( $source );
		return ( false === $body ) ? null : $body;
	}

	return null;
}

/**
 * Whether any coordinate in a (possibly deeply nested) coordinate array falls
 * inside the bounding box. GeoJSON coordinates are [lon, lat].
 *
 * @param mixed $coords Coordinate node.
 * @param array $bbox   [minLon, minLat, maxLon, maxLat].
 * @return bool
 */
function cac_coords_in_bbox( $coords, $bbox ) {
	// Leaf node: [lon, lat].
	if ( isset( $coords[0], $coords[1] ) && is_numeric( $coords[0] ) && is_numeric( $coords[1] ) ) {
		$lon = (float) $coords[0];
		$lat = (float) $coords[1];
		return ( $lon >= $bbox[0] && $lon <= $bbox[2] && $lat >= $bbox[1] && $lat <= $bbox[3] );
	}

	if ( is_array( $coords ) ) {
		foreach ( $coords as $child ) {
			if ( cac_coords_in_bbox( $child, $bbox ) ) {
				return true;
			}
		}
	}

	return false;
}
