/**
 * Map Handler for PiClock Web
 * Handles map initialization, location selection, and geocoding
 */

class MapHandler {
    constructor(options = {}) {
        // Default options
        this.options = {
            mapContainer: 'map',
            latitudeInput: 'latitude',
            longitudeInput: 'longitude',
            locationInput: 'location',
            defaultLocation: { lat: 9.0820, lng: 8.6753 }, // Default to Nigeria center
            defaultZoom: 6,
            markerZoom: 13,
            tileLayer: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            tileAttribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            ...options
        };

        // Initialize map
        this.map = null;
        this.marker = null;
        this.initialized = false;
        this.geocoder = null;

        // Bind methods
        this.init = this.init.bind(this);
        this.setupEventListeners = this.setupEventListeners.bind(this);
        this.updateMarker = this.updateMarker.bind(this);
        this.geocodeAddress = this.geocodeAddress.bind(this);
        this.getCurrentLocation = this.getCurrentLocation.bind(this);
        this.updateFormFields = this.updateFormFields.bind(this);
    }

    /**
     * Initialize the map
     */
    init() {
        // Check if map container exists
        const mapElement = document.getElementById(this.options.mapContainer);
        if (!mapElement) return;

        // Initialize the map
        this.map = L.map(this.options.mapContainer).setView(
            [this.options.defaultLocation.lat, this.options.defaultLocation.lng],
            this.options.defaultZoom
        );

        // Add OpenStreetMap tile layer
        L.tileLayer(this.options.tileLayer, {
            attribution: this.options.tileAttribution
        }).addTo(this.map);

        // Initialize marker
        this.marker = L.marker(this.map.getCenter(), {
            draggable: true
        }).addTo(this.map);

        // Update form fields when marker is moved
        this.marker.on('dragend', () => {
            const latLng = this.marker.getLatLng();
            this.updateFormFields(latLng.lat, latLng.lng);
            this.reverseGeocode(latLng.lat, latLng.lng);
        });

        // Update marker position on map click
        this.map.on('click', (e) => {
            this.updateMarker(e.latlng);
            this.updateFormFields(e.latlng.lat, e.latlng.lng);
            this.reverseGeocode(e.latlng.lat, e.latlng.lng);
        });

        // Try to get user's current location
        this.getCurrentLocation();

        // Setup event listeners for address search
        this.setupEventListeners();

        this.initialized = true;
        console.log('Map initialized');
    }

    /**
     * Set up event listeners for the search form
     */
    setupEventListeners() {
        const searchForm = document.getElementById('location-search-form');
        const currentLocationBtn = document.getElementById('get-current-location');

        if (searchForm) {
            searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const address = document.getElementById('location-search').value;
                if (address) {
                    this.geocodeAddress(address);
                }
            });
        }

        if (currentLocationBtn) {
            currentLocationBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.getCurrentLocation();
            });
        }
    }

    /**
     * Update marker position and center map
     */
    updateMarker(latLng) {
        if (!this.initialized) return;
        
        this.marker.setLatLng(latLng);
        this.map.setView(latLng, this.options.markerZoom);
    }

    /**
     * Update form fields with latitude and longitude
     */
    updateFormFields(lat, lng) {
        const latInput = document.getElementById(this.options.latitudeInput);
        const lngInput = document.getElementById(this.options.longitudeInput);
        
        if (latInput) latInput.value = lat.toFixed(6);
        if (lngInput) lngInput.value = lng.toFixed(6);
    }

    /**
     * Geocode an address and update the map
     */
    async geocodeAddress(address) {
        if (!this.initialized) return;

        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}`);
            const data = await response.json();
            
            if (data && data.length > 0) {
                const { lat, lon, display_name } = data[0];
                const latLng = L.latLng(parseFloat(lat), parseFloat(lon));
                
                this.updateMarker(latLng);
                this.updateFormFields(latLng.lat, latLng.lng);
                
                // Update location input with formatted address
                const locationInput = document.getElementById(this.options.locationInput);
                if (locationInput) {
                    locationInput.value = display_name;
                }
                
                return true;
            } else {
                throw new Error('Location not found');
            }
        } catch (error) {
            console.error('Geocoding error:', error);
            alert('Could not find the location. Please try a different address.');
            return false;
        }
    }

    /**
     * Reverse geocode coordinates to get address
     */
    async reverseGeocode(lat, lng) {
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
            const data = await response.json();
            
            if (data && data.display_name) {
                const locationInput = document.getElementById(this.options.locationInput);
                if (locationInput) {
                    locationInput.value = data.display_name;
                }
                return data.display_name;
            }
        } catch (error) {
            console.error('Reverse geocoding error:', error);
        }
        return null;
    }

    /**
     * Get user's current location
     */
    getCurrentLocation() {
        if (!navigator.geolocation) {
            console.warn('Geolocation is not supported by your browser');
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const latLng = L.latLng(position.coords.latitude, position.coords.longitude);
                this.updateMarker(latLng);
                this.updateFormFields(latLng.lat, latLng.lng);
                this.reverseGeocode(latLng.lat, latLng.lng);
            },
            (error) => {
                console.warn('Error getting current location:', error);
                // Default to a location if geolocation fails
                this.updateMarker(L.latLng(this.options.defaultLocation.lat, this.options.defaultLocation.lng));
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    }
}

// Initialize map when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    const mapHandler = new MapHandler({
        mapContainer: 'map',
        latitudeInput: 'latitude',
        longitudeInput: 'longitude',
        locationInput: 'location',
        defaultLocation: { lat: 9.0820, lng: 8.6753 }, // Center of Nigeria
        defaultZoom: 6,
        markerZoom: 15
    });

    mapHandler.init();
});
