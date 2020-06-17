const
	os        = require( 'os' ),
	fs        = require( 'fs' ),
	https     = require( 'https' ),
	process   = require( 'process' ),
	gulp      = require( 'gulp' ),
	Geonames  = require( 'geonames.js' ),
	formatter = require( 'currency-formatter' ),
	jsdom     = require( 'jsdom' ),
	{ JSDOM } = jsdom,
	tmpPath = `${process.cwd()}/tmp`;

function getAuth( key ) {

	const authPath = os.homedir() + '/.llmsdevrc';
	if ( fs.existsSync( authPath ) ) {
		auth = JSON.parse( fs.readFileSync( authPath, 'utf8' ) );
	}

	if ( ! auth || ! auth[ key ] ) {
		console.log( 'No authorization, cannot proceed.' );
		process.exit( 1 );
	}

	return auth[ key ];

}

function getDom( url, cb ) {

	https.get( url, res => {
		let data = '';

		res.on( 'data', chunk => {
			data += chunk;
		} );

		res.on( 'end', () => {
			cb( new JSDOM( data ) );
		} );
	} );
}

function getAuthorize( cb ) {
	cb( [ 'AU', 'CA', 'GB', 'US' ] );
}

function getPayPal( cb ) {

	getDom( 'https://www.paypal.com/us/webapps/mpp/country-worldwide', dom => {
		const list = [];
		dom.window.document.querySelectorAll( '.country-row a' ).forEach( country => {
			list.push( country.href.split( '/' ).filter( part => part )[0].toUpperCase() );
		} );
		cb( list );
	} );

}

function getStripe( cb ) {

	const stripe = getAuth( 'stripe' );

	https.get( {
		host: 'api.stripe.com',
		path: '/v1/country_specs?limit=100',
		headers: {
			Authorization: 'Basic ' + Buffer.from( stripe.sk + ':').toString( 'base64' )
		}
	}, res => {

		let data = '';

		res.on( 'data', chunk => {
			data += chunk;
		} );

		res.on( 'end', () => {
			const json = JSON.parse( data );
			const list = [];
			json.data.forEach( country => {
				list.push( country.id );
			} )
			cb( list );
		} );

	} );

}

function getPlatformSupport( cb ) {

	const fileName = `${ tmpPath }/ecomm-platform-support.json`;

	if ( fs.existsSync( fileName ) ) {
		return cb( JSON.parse( fs.readFileSync( fileName, 'utf8' ) ) );
	}

	const support = {
		platforms: {},
		countries: {},
	};

	function addSupport( platform, list ) {
		support.platforms[ platform ] = list;
		list.forEach( country => {
			if ( ! support.countries[ country ] ) {
				support.countries[ country ] = [];
			}
			support.countries[ country ].push( platform );
		} );
	}

	getAuthorize( list => {
		addSupport( 'authorize', list );
	} );

	getPayPal( list => {
		addSupport( 'paypal', list );
	} )

	getStripe( list => {
		addSupport( 'stripe', list );
	} );

	const wait = setInterval( () => {

		if ( 3 === Object.keys( support.platforms ).length ) {

			clearInterval( wait );

			writeTmpFile( fileName, support );

			cb( support );

		}

	}, 500 );

}


const writeTmpFile = ( file, obj ) => {
	return fs.writeFileSync( file, JSON.stringify( obj, null, 2 ), 'utf8' );
};

const writeI18nFile = ( title, desc, object, i18n = true, numeric_keys = true ) => {

	const toPHP = ( obj, level ) => {

		level = level || 1;
		const tabs = '\t'.repeat( level );

		let php = [];
		Object.keys( obj ).forEach( key => {

			let item = obj[ key ];

			if ( 'object' === typeof item ) {

				if ( 0 === Object.keys( item ).length ) {
					php.push( `${ tabs }'${ key }' => array(),` );
				} else {
					php.push( `${ tabs }'${ key }' => array(` );
					php = php.concat( toPHP( item, level + 1 ) );
					php.push( `${ tabs }),` );
				}

			} else {

				if ( i18n ) {
					item = `__( '${ item.replace( /\'/g, '\\\'' ) }', 'lifterlms' )`;
				} else {
					item = 'string' === typeof item ? `'${ item.replace( /\'/g, '\\\'' ) }'` : item;
				}

				const line = ! numeric_keys && ! isNaN( key ) ? `${ tabs }${ item },` : `${ tabs }'${ key }' => ${ item },`;

				php.push( line );

			}

		} );

		return php;

	};

	const php = toPHP( object ).join( '\n' );

	const str = `<?php
/**
 * ${title}
 *
 * ${desc}
 *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *                                                                         *
 * Data provided by GeoNames (https://www.geonames.org/)                   *
 * under the CC4.0 License (https://creativecommons.org/licenses/by/4.0/). *
 *                                                                         *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *
 *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *                                                                         *
 * Note to contributors                                                    *
 *                                                                         *
 * The data contained within this file is automatically generated. Do not  *
 * modify or submit pull requests on this file directly. If you've located *
 * an issue with any of the data contained within this file please open a  *
 * new issue at https://github.com/gocodebox/lifterlms/issues/new/choose.  *
 *                                                                         *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *
 * @package LifterLMS/i18n
 *
 * @since 3.37.0 TODO
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

return array(
${ php }
);
`;

	return fs.writeFileSync( `${ process.cwd() }/languages/${ title.toLowerCase().replace( ' ', '-' ) }.php`, str, 'utf8' );

};

const getGeonames = () => {

	const username = getAuth( 'geonames' );

	return new Geonames({
		username,
		lan: 'en',
		encoding: 'JSON'
	} );

};


const getCountriesList = async () => {

	const tmpFile = `${tmpPath}/countries.json`;

	if ( fs.existsSync( tmpFile ) ) {
		return JSON.parse( fs.readFileSync( tmpFile ) ).geonames;
	}

	const geonames = getGeonames();

	return geonames.countryInfo( {} )
		.then( countries => {
			writeTmpFile( tmpFile, countries );
			return countries.geonames;
		} )
		.then( countries => {

			const getCountryName = ( country ) => {
				let name = country.countryName;

				switch( country.countryCode ) {

					case 'GB':
						name += ' (UK)';
						break;

					case 'US':
						name += ' (US)';
						break;

				}

				return name;
			};

			let list = {};
			countries.forEach( country => {
				list[ country.countryCode ] = getCountryName( country );
			} );

			writeI18nFile( 'Countries', 'Returns an array of countries and their country codes.', list );

			return countries;

		} );

};

gulp.task( 'loc-get-countries', function( cb ) {

	getCountriesList()
		.then( countries => {

			const getCountryName = ( country ) => {
				let name = country.countryName;

				switch( country.countryCode ) {

					case 'GB':
						name += ' (UK)';
						break;

					case 'US':
						name += ' (US)';
						break;

				}

				return name;
			};

			let list = {};
			countries.forEach( country => {
				list[ country.countryCode ] = getCountryName( country );
			} );

			writeI18nFile( 'Countries', 'Returns an array of countries and their country codes.', list );

			cb();

		} );

} );

gulp.task( 'loc-get-states', function( cb ) {

	getCountriesList()
		.then( countries => {

			const geonames = getGeonames();

			( async function loop() {

				let list = {};

				for ( let i = 0; i < countries.length; i++ ) {

					const states = await geonames.children( { geonameId: countries[ i ].geonameId } );
					writeTmpFile( `${ tmpPath }/state-${ countries[ i ].countryName }.json`, states );


					const count = states.totalResultsCount ? states.totalResultsCount : 0;
					console.log( `${ countries[ i ].countryName }: ${ count }` );

					let stateList = {};
					if ( count ) {
						states.geonames.forEach( state => {
							const key = state.adminCodes1 && state.adminCodes1.ISO3166_2 ? state.adminCodes1.ISO3166_2 : state.adminCode1;
							stateList[ key ] = state.name;
						} );
					}
					list[ countries[ i ].countryCode ] = stateList;

				}

				writeI18nFile( 'States', 'Returns a multi-demensional array of countries and country states (or provinces / regions) and their respective codes.\nCountries with an empty array have no states.', list );

			} )();

			cb();

		} );

} );

gulp.task( 'loc-get-info', function( cb ) {

	getCountriesList()
		.then( countries => {

			getPlatformSupport( function( platforms ) {

				let list = {};

				for ( let i = 0; i < countries.length; i++ ) {

					const formatData = formatter.findCurrency( countries[ i ].currencyCode );
					if ( formatData ) {

						list[ countries[ i ].countryCode ] = {
							currency: {
								code: countries[ i ].currencyCode,
								symbol_position: formatData.symbolOnLeft ? 'left' : 'right',
								thousand_separator: formatData.thousandsSeparator,
								decimal_separator: formatData.decimalSeparator,
								decimals: formatData.decimalDigits,
							},
							platforms: platforms.countries[ countries[ i ].countryCode ] || [],
						};

					}

				}

				writeI18nFile( 'Locale Info', 'Returns an array of associative arrays containing localization information about the specified country.', list, false, false );

				cb();

			} );

		} );

} );

