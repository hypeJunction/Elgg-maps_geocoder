Geocoder for Elgg
=================
![Elgg 2.0](https://img.shields.io/badge/Elgg-2.0.x-orange.svg?style=flat-square)

## Features

 * Geocode locations using Google Maps Geoocoding API
 * Automatically geocode entity locations on create and update events

## Usage

`location` metadata will be geocoded automatically whenever an entity is saved.
If you need to geocode an address, use `maps_geocoder_geocode()`.