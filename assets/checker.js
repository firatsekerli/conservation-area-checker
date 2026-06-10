/**
 * Conservation Area Checker - front-end script.
 *
 * This single file handles two jobs:
 *   1. The postcode entry form (shortcode output): validate the format and
 *      redirect to the results page with the postcode in the query string.
 *   2. The results page: fetch the conservation area and Article 4 GeoJSON,
 *      run a point-in-polygon test against the visitor's coordinates, and
 *      render the matching result state.
 *
 * ---------------------------------------------------------------------------
 * DEVELOPER HANDOFF: swapping in production data
 * ---------------------------------------------------------------------------
 * In development this page is given two data URLs from PHP, both pointing at:
 *   /wp-content/plugins/conservation-area-checker/data/sample-conservation-areas.json
 *
 * The URLs arrive on the result element as data-conservation-url and
 * data-article4-url (set in includes/class-results-page.php, render_in_area()).
 * Change them there, not here, to point at real data.
 *
 * 1. WHERE TO REPLACE THE SAMPLE PATH
 *    The sample path is set in PHP (render_in_area). Replace the two
 *    $geojson_url / $article4_url values with your production file paths or a
 *    serverless endpoint URL, then this script picks them up automatically.
 *
 * 2. RECOMMENDED PRODUCTION APPROACH
 *    a) Preferred for simplicity: host a pre-filtered regional GeoJSON file in
 *       this plugin's data/ folder and point the PHP URLs at it. No server
 *       round trips, easy to cache on the CDN.
 *    b) Alternative: proxy through a small PHP endpoint in this plugin that
 *       fetches and caches the Planning Data API response (for example with a
 *       transient), so the large upstream files are downloaded once and the
 *       client only ever sees the filtered result.
 *
 * 3. CONSERVATION AREA DATASET (Planning Data)
 *    https://www.planning.data.gov.uk/dataset/conservation-area.geojson
 *
 * 4. ARTICLE 4 DIRECTION DATASET (Planning Data)
 *    https://www.planning.data.gov.uk/dataset/article-4-direction-area.geojson
 *
 * 5. NOTE ON SIZE
 *    Both datasets cover the whole of England and are large. Filter them down
 *    to the service area (a bounding box around your centre, or the relevant
 *    counties) before hosting. Serving the national files to every visitor
 *    would be slow and wasteful.
 * ---------------------------------------------------------------------------
 */
(function () {
	'use strict';

	// UK postcode format. Mirrors the PHP regex in class-geocheck.php.
	var POSTCODE_RE = /^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i;

	/**
	 * Wire up the postcode entry form: validate, then redirect.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initSearchForm(form) {
		var input = form.querySelector('.cac-search-input');
		var error = form.querySelector('.cac-search-error');

		function hideError() {
			if (error) {
				error.hidden = true;
			}
			input.classList.remove('cac-search-input-invalid');
		}

		function showError() {
			if (error) {
				error.hidden = false;
			}
			input.classList.add('cac-search-input-invalid');
			input.focus();
		}

		input.addEventListener('input', hideError);

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var value = (input.value || '').trim();
			if (!POSTCODE_RE.test(value)) {
				showError();
				return;
			}

			// Strip spaces before appending to the URL, for example GU514BY.
			var compact = value.replace(/\s+/g, '').toUpperCase();

			var base = (window.cacChecker && window.cacChecker.resultsUrl)
				? window.cacChecker.resultsUrl
				: '/conservation-area-checker/';

			var separator = base.indexOf('?') === -1 ? '?' : '&';
			window.location.href = base + separator + 'postcode=' + encodeURIComponent(compact);
		});
	}

	/**
	 * Ray-casting point-in-polygon test for a single linear ring.
	 *
	 * @param {number} lon Point longitude (x).
	 * @param {number} lat Point latitude (y).
	 * @param {Array} ring Array of [lon, lat] coordinate pairs.
	 * @return {boolean}
	 */
	function pointInRing(lon, lat, ring) {
		var inside = false;
		for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
			var xi = ring[i][0];
			var yi = ring[i][1];
			var xj = ring[j][0];
			var yj = ring[j][1];

			var intersects = ((yi > lat) !== (yj > lat)) &&
				(lon < (xj - xi) * (lat - yi) / (yj - yi) + xi);

			if (intersects) {
				inside = !inside;
			}
		}
		return inside;
	}

	/**
	 * Point-in-polygon for a GeoJSON Polygon coordinate array.
	 * The first ring is the outer boundary; later rings are holes.
	 *
	 * @param {number} lon
	 * @param {number} lat
	 * @param {Array} polygon Array of rings.
	 * @return {boolean}
	 */
	function pointInPolygon(lon, lat, polygon) {
		if (!polygon || !polygon.length) {
			return false;
		}
		if (!pointInRing(lon, lat, polygon[0])) {
			return false;
		}
		// Inside the outer ring: a hit on any hole means we are outside.
		for (var h = 1; h < polygon.length; h++) {
			if (pointInRing(lon, lat, polygon[h])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Test a point against a whole GeoJSON FeatureCollection.
	 * Handles Polygon and MultiPolygon geometries.
	 *
	 * @param {number} lon
	 * @param {number} lat
	 * @param {Object} geojson
	 * @return {boolean}
	 */
	function pointInGeoJSON(lon, lat, geojson) {
		if (!geojson) {
			return false;
		}

		var features = geojson.features || [];
		for (var f = 0; f < features.length; f++) {
			var geometry = features[f] && features[f].geometry;
			if (!geometry) {
				continue;
			}

			if (geometry.type === 'Polygon') {
				if (pointInPolygon(lon, lat, geometry.coordinates)) {
					return true;
				}
			} else if (geometry.type === 'MultiPolygon') {
				var polys = geometry.coordinates || [];
				for (var p = 0; p < polys.length; p++) {
					if (pointInPolygon(lon, lat, polys[p])) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Fetch and parse a GeoJSON URL. Resolves to null on any failure so the
	 * check can degrade gracefully rather than break the page.
	 *
	 * @param {string} url
	 * @return {Promise<Object|null>}
	 */
	function fetchGeoJSON(url) {
		if (!url) {
			return Promise.resolve(null);
		}
		return fetch(url, { credentials: 'same-origin' })
			.then(function (response) {
				return response.ok ? response.json() : null;
			})
			.catch(function () {
				return null;
			});
	}

	// Result copy. Kept here so the JS-rendered states match the PHP wording.
	var COPY = {
		none: 'This postcode does not appear to be within a conservation area or Article 4 Direction area. Standard permitted development rules are likely to apply. Your surveyor will confirm during your free visit.',
		conservation: 'This postcode appears to be within a conservation area. Replacement windows and doors may need to match the existing style and material. Our surveyor will advise during your free visit.',
		article4: 'This area may be subject to an Article 4 Direction. This can mean planning permission is required before replacing windows or doors on elevations visible from the road. Rules vary by street and property type, so our surveyor will confirm what applies to your home.'
	};

	/**
	 * Paint the result state into the container.
	 *
	 * @param {HTMLElement} container
	 * @param {boolean} inConservation
	 * @param {boolean} inArticle4
	 */
	function renderResult(container, inConservation, inArticle4) {
		var inner = container.querySelector('.cac-result-inner');
		if (!inner) {
			return;
		}

		container.classList.remove(
			'cac-state-loading',
			'cac-state-none',
			'cac-state-conservation',
			'cac-state-article4',
			'cac-state-both'
		);

		var paragraphs = [];
		var stateClass;

		if (inConservation && inArticle4) {
			stateClass = 'cac-state-both';
			paragraphs.push(COPY.conservation);
			paragraphs.push(COPY.article4);
		} else if (inConservation) {
			stateClass = 'cac-state-conservation';
			paragraphs.push(COPY.conservation);
		} else if (inArticle4) {
			stateClass = 'cac-state-article4';
			paragraphs.push(COPY.article4);
		} else {
			stateClass = 'cac-state-none';
			paragraphs.push(COPY.none);
		}

		container.classList.add(stateClass);

		var html = '';
		for (var i = 0; i < paragraphs.length; i++) {
			html += '<p>' + paragraphs[i] + '</p>';
		}
		inner.innerHTML = html;
	}

	/**
	 * Run the client-side checks for the results page container.
	 *
	 * @param {HTMLElement} container
	 */
	function initResult(container) {
		var coords;
		try {
			coords = JSON.parse(container.getAttribute('data-coords'));
		} catch (e) {
			coords = null;
		}

		if (!coords || typeof coords.lat !== 'number' || typeof coords.lon !== 'number') {
			// Without coordinates we cannot run the check. Fall back to "none"
			// so the visitor still gets the guidance and CTA.
			renderResult(container, false, false);
			return;
		}

		var conservationUrl = container.getAttribute('data-conservation-url');
		var article4Url = container.getAttribute('data-article4-url');

		Promise.all([
			fetchGeoJSON(conservationUrl),
			fetchGeoJSON(article4Url)
		]).then(function (results) {
			var conservationData = results[0];
			var article4Data = results[1];

			var inConservation = pointInGeoJSON(coords.lon, coords.lat, conservationData);
			var inArticle4 = pointInGeoJSON(coords.lon, coords.lat, article4Data);

			renderResult(container, inConservation, inArticle4);
		});
	}

	/**
	 * Boot: attach to any forms and any results container on the page.
	 */
	function init() {
		var forms = document.querySelectorAll('[data-cac-search]');
		for (var i = 0; i < forms.length; i++) {
			initSearchForm(forms[i]);
		}

		var results = document.querySelectorAll('[data-cac-result]');
		for (var j = 0; j < results.length; j++) {
			initResult(results[j]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
