/**
 * JavaScript for managing the Routes page, including table interactions
 * and displaying route details with a map in a modal.
 */
$(document).ready(function () {

    // --- Tooltip Initialization ---
    // Initialize tooltips using Bootstrap's JavaScript component
    $('[data-toggle="tooltip"]').tooltip();

    // --- Map and Route Visualization Logic ---
    const routeMapContainer = document.getElementById('mapContainer');
    const loadingIndicator = document.getElementById('loadingIndicator');
    let routeMap = null; // Use a single variable for the route map instance

    // Initialize the route map if it doesn't exist
    function initializeRouteMap() {
        if (!routeMap) {
            console.log("Initializing route map...");
            // Set a default view or bounds that makes sense for your location if known,
            // otherwise, a generic world view is fine until route data loads.
            // Using a center point for Brazil as an example
            routeMap = L.map(routeMapContainer).setView([-14.235, -51.9253], 4); // Center of Brazil, initial zoom

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(routeMap);
        }
    }

    // Clears layers from the route map, except the base tile layer
    function clearRouteMapLayers() {
        console.log("Clearing route map layers...");
        if (routeMap) {
            routeMap.eachLayer((layer) => {
                // Check if the layer is NOT an instance of the TileLayer (our base map)
                if (!(layer instanceof L.TileLayer)) {
                    routeMap.removeLayer(layer);
                }
            });
             // Reset map view to a default if no route is loaded
            // routeMap.setView([-14.235, -51.9253], 4);
        }
    }

    // Shows the loading indicator
    function showLoadingIndicator() {
        console.log("Showing loading indicator...");
        // Using jQuery to match $(document).ready context
        $('#loadingIndicator').show();
    }

    // Hides the loading indicator
    function hideLoadingIndicator() {
        console.log("Hiding loading indicator...");
         // Using jQuery
        $('#loadingIndicator').hide();
    }

    // Calculates the total distance of a polyline (array of [lat, lon] or [y, x]) in kilometers
    // Uses the Haversine formula.
    function calculatePolylineDistance(coordinates) {
        let totalDistance = 0;
        // Ensure coordinates are in [latitude, longitude] format for Leaflet/Haversine compatibility
        const latLngCoords = coordinates.map(coord => Array.isArray(coord) ? [coord[0], coord[1]] : [coord.y, coord.x]);


        for (let i = 0; i < latLngCoords.length - 1; i++) {
            const lat1 = latLngCoords[i][0];
            const lon1 = latLngCoords[i][1];
            const lat2 = latLngCoords[i + 1][0];
            const lon2 = latLngCoords[i + 1][1];

            // Haversine formula implementation
            const R = 6371; // Radius of Earth in km
            const dLat = toRad(lat2 - lat1);
            const dLon = toRad(lon2 - lon1);
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            totalDistance += R * c; // Distance in km
        }

        return totalDistance;
    }

    // Helper function to convert degrees to radians
    function toRad(degrees) {
        return degrees * Math.PI / 180;
    }

    // Gets a color for a polyline based on its average speed
    function getColorForSpeed(avgSpeed) {
         const speed = parseFloat(avgSpeed);
         if (isNaN(speed)) return 'gray'; // Default color if speed is not a number

        if (speed < 30) {
            return '#d9534f'; // Red - Low speed / Significant delay (Bootstrap danger)
        } else if (speed < 60) {
            return '#f0ad4e'; // Orange - Moderate speed / Warning (Bootstrap warning)
        } else {
            return '#5cb85c'; // Green - Higher speed / Normal (Bootstrap success)
        }
    }

    // Draws a subroute (segment) polyline on the map
    function drawSubroutePolyline(subrouteData) {
         if (!routeMap || !subrouteData || !Array.isArray(subrouteData.route_points) || subrouteData.route_points.length < 2) {
             console.warn("Invalid subroute data provided, cannot draw polyline.");
             return;
         }

         // Ensure coordinates are in [latitude, longitude] format for Leaflet
         const subrouteCoordinates = subrouteData.route_points.map(point => [parseFloat(point.y), parseFloat(point.x)]);

         // Calculate the color based on the subroute's average speed
         const color = getColorForSpeed(subrouteData.avg_speed);

         const polyline = L.polyline(subrouteCoordinates, {
             color: color, // Color based on speed
             weight: 6,
             opacity: 0.8,
             lineCap: 'round' // Optional: makes line caps round
         }).addTo(routeMap);

         // Store original color and speed data on the polyline layer itself
         polyline.originalColor = color;
         polyline.avgSpeed = parseFloat(subrouteData.avg_speed);
         polyline.distanceKm = calculatePolylineDistance(subrouteCoordinates);


         // Add click listener to show details and highlight
         polyline.on('click', function () {
             console.log("Polyline clicked", subrouteData);

             const distance = polyline.distanceKm;
             const avgSpeed = polyline.avgSpeed;

             // Calculate time in minutes (handle division by zero/NaN)
             const time = (!isNaN(avgSpeed) && avgSpeed > 0) ? (distance / avgSpeed) * 60 : NaN;

             // Format values
             const formattedSpeed = !isNaN(avgSpeed) ? avgSpeed.toFixed(1) : 'N/A';
             const formattedDistance = !isNaN(distance) ? distance.toFixed(2) : 'N/A';
             const formattedTime = !isNaN(time) ? time.toFixed(1) : 'N/A';

             const popupContent = `
                 <strong>Trecho da Rota</strong><br>
                 <b>Velocidade Média:</b> ${formattedSpeed} km/h<br>
                 <b>Distância:</b> ${formattedDistance} km<br>
                 <b>Tempo Estimado:</b> ${formattedTime} min
             `;

             // Bind and open popup - reuse existing if open
             if (polyline.getPopup()) {
                  polyline.setPopupContent(popupContent).openPopup();
             } else {
                 polyline.bindPopup(popupContent).openPopup();
             }


             // Highlight the clicked trecho (segment)
             polyline.setStyle({
                 color: '#0275d8', // Bootstrap primary/info color for highlighting
                 weight: 8,
                 opacity: 1
             });

             // Reset style for other polylines
             routeMap.eachLayer(function(layer) {
                 if (layer instanceof L.Polyline && layer !== polyline) {
                     // Restore original color and style
                     layer.setStyle({
                         color: layer.originalColor,
                         weight: 6,
                         opacity: 0.8
                     });
                 }
             });

             // Optional: Center map view on the clicked segment
              try {
                   if (polyline.getBounds) {
                        routeMap.fitBounds(polyline.getBounds(), { padding: [50, 50] }); // Adjust padding as needed
                   }
              } catch (e) {
                   console.warn("Could not fit bounds for polyline:", e);
              }
         });

         // Add listener to restore color when popup is closed (by clicking away)
         polyline.on('popupclose', function () {
              polyline.setStyle({
                  color: polyline.originalColor, // Restore original color
                  weight: 6,
                  opacity: 0.8
              });
         });
    }

    // Fetches route details and subroutes from the API and draws them on the map
    function loadRouteDetails(routeId) {
        showLoadingIndicator();
        clearRouteMapLayers(); // Clear map before loading new data
        initializeRouteMap(); // Ensure map is initialized

        const apiUrl = `../api.php?action=get_route_details&route_id=${routeId}`; // Assuming an API endpoint for route details

        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log("Route Details API response:", data);

                // --- Update Modal Statistics ---
                const modalRouteName = $('#modalRouteName');
                const modalAvgSpeed = $('#modalAvgSpeed');
                const modalHistoricSpeed = $('#modalHistoricSpeed');
                const speedProgressBar = $('#speedProgressBar');
                const irregularitiesList = $('#irregularitiesList'); // Get the irregularities list element

                // Assuming the API response includes overall route stats
                // If not, you'll need to get these from the table row itself or another source.
                // For this example, let's assume 'data' contains overall route info
                 if (data && data.overall_stats) { // Example: API returns { overall_stats: { ... }, subroutes: [ ... ] }
                     modalRouteName.text(data.overall_stats.name || 'N/A');
                     const avgSpeed = parseFloat(data.overall_stats.avg_speed);
                     const historicSpeed = parseFloat(data.overall_stats.historic_speed);

                     modalAvgSpeed.text(isNaN(avgSpeed) ? '- km/h' : `${avgSpeed.toFixed(1)} km/h`);
                     modalHistoricSpeed.text(isNaN(historicSpeed) ? '- km/h' : `${historicSpeed.toFixed(1)} km/h`);

                     // Update progress bar
                     if (!isNaN(avgSpeed) && !isNaN(historicSpeed) && historicSpeed > 0) {
                         const progressWidth = Math.min((avgSpeed / historicSpeed) * 100, 100); // Cap at 100%
                         speedProgressBar.css('width', `${progressWidth}%`).attr('aria-valuenow', progressWidth);
                          // Optional: Change progress bar color based on performance
                          if (progressWidth < 50) {
                              speedProgressBar.removeClass('bg-success bg-warning').addClass('bg-danger');
                          } else if (progressWidth < 80) {
                               speedProgressBar.removeClass('bg-success bg-danger').addClass('bg-warning');
                          } else {
                               speedProgressBar.removeClass('bg-warning bg-danger').addClass('bg-success');
                          }
                     } else {
                          speedProgressBar.css('width', '0%').attr('aria-valuenow', 0).removeClass('bg-success bg-warning bg-danger');
                     }

                 } else {
                     // Fallback if overall stats not in API response - try to get from table row if modal triggered by button click
                     const clickedRow = $(`tr[data-uuid="${routeId}"]`); // Find the table row
                     if (clickedRow.length) {
                          modalRouteName.text(clickedRow.find('.route-name').attr('title') || 'N/A'); // Get name from tooltip title
                          // You might need data attributes on table cells for speed/time to easily grab them
                          // Or parse the text content, but data attributes are safer.
                          // Example using parsing text (less robust):
                          const avgSpeedText = clickedRow.find('td:nth-child(6)').text().replace(' Km/h', '').trim(); // 6th column (Vel. Atual)
                          const historicSpeedText = clickedRow.find('.d-none.d-lg-table-cell:nth-child(1)').text().replace(' Km/h', '').trim(); // 4th column (Vel. Normal) - Adjust selector as needed

                          const avgSpeed = parseFloat(avgSpeedText);
                          const historicSpeed = parseFloat(historicSpeedText);

                           modalAvgSpeed.text(isNaN(avgSpeed) ? '- km/h' : `${avgSpeed.toFixed(1)} km/h`);
                           modalHistoricSpeed.text(isNaN(historicSpeed) ? '- km/h' : `${historicSpeed.toFixed(1)} km/h`);

                            if (!isNaN(avgSpeed) && !isNaN(historicSpeed) && historicSpeed > 0) {
                                const progressWidth = Math.min((avgSpeed / historicSpeed) * 100, 100);
                                speedProgressBar.css('width', `${progressWidth}%`).attr('aria-valuenow', progressWidth);
                                 if (progressWidth < 50) {
                                    speedProgressBar.removeClass('bg-success bg-warning').addClass('bg-danger');
                                } else if (progressWidth < 80) {
                                     speedProgressBar.removeClass('bg-success bg-danger').addClass('bg-warning');
                                } else {
                                     speedProgressBar.removeClass('bg-warning bg-danger').addClass('bg-success');
                                }
                            } else {
                                 speedProgressBar.css('width', '0%').attr('aria-valuenow', 0).removeClass('bg-success bg-warning bg-danger');
                            }

                     } else {
                          modalRouteName.text('Detalhes da Rota'); // Default title if row not found
                          modalAvgSpeed.text('- km/h');
                          modalHistoricSpeed.text('- km/h');
                           speedProgressBar.css('width', '0%').attr('aria-valuenow', 0).removeClass('bg-success bg-warning bg-danger');
                     }
                 }


                // --- Draw Subroutes on Map ---
                if (data && Array.isArray(data.subroutes) && data.subroutes.length > 0) {
                    data.subroutes.forEach(subroute => {
                        drawSubroutePolyline(subroute); // Draw each subroute
                    });

                    // Fit map bounds to all drawn layers (polylines)
                     try {
                         const bounds = L.featureGroup(data.subroutes.map(subroute =>
                             L.polyline(subroute.route_points.map(p => [parseFloat(p.y), parseFloat(p.x)]))
                         )).getBounds();
                         if (bounds.isValid()) {
                             routeMap.fitBounds(bounds, { padding: [50, 50] }); // Adjust padding as needed
                         }
                     } catch (e) {
                         console.warn("Could not fit map bounds to subroutes:", e);
                     }


                    // --- Populate Irregularities List ---
                    // Assuming subroute data might contain irregularity points
                    // Or you might need a separate API call for irregularities on this route
                    // For now, let's check if subroute data has an 'irregularities' property (example structure)
                    let hasIrregularities = false;
                     irregularitiesList.empty(); // Clear previous list items

                    data.subroutes.forEach((subroute, subIndex) => {
                        if (subroute.irregularities && Array.isArray(subroute.irregularities) && subroute.irregularities.length > 0) {
                             hasIrregularities = true;
                             subroute.irregularities.forEach((irr, irrIndex) => {
                                  irregularitiesList.append(`
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                          <span><i class="fas fa-map-marker-alt text-danger mr-2"></i>Ponto de Irregularidade ${subIndex + 1}.${irrIndex + 1}</span>
                                          <span class="badge badge-secondary">Lat: ${parseFloat(irr.y).toFixed(5)}, Lon: ${parseFloat(irr.x).toFixed(5)}</span>
                                      </li>
                                  `);
                             });
                        }
                    });

                    if (!hasIrregularities) {
                         // Display default message if no irregularities found in subroutes
                         irregularitiesList.html('<li class="list-group-item text-muted">Nenhuma irregularidade detectada neste momento nos trechos monitorados.</li>');
                    }


                } else {
                    console.log("No subroute data received or subroutes array is empty.");
                    // If no subroutes but main route line was drawn, center on that.
                    // If no data at all, maybe show a message on the map or reset view.
                    alert("Detalhes dos trechos da rota não disponíveis.");
                     irregularitiesList.html('<li class="list-group-item text-muted">Detalhes de irregularidades não disponíveis.</li>');
                }
            })
            .catch(error => {
                console.error("Error loading route details:", error);
                alert("Ocorreu um erro ao carregar os detalhes da rota. Verifique o console para mais informações.");
                 // Update modal with error state
                 $('#modalRouteName').text('Erro ao Carregar');
                 $('#modalAvgSpeed').text('- km/h');
                 $('#modalHistoricSpeed').text('- km/h');
                 $('#speedProgressBar').css('width', '0%').attr('aria-valuenow', 0).removeClass('bg-success bg-warning bg-danger');
                 $('#irregularitiesList').html('<li class="list-group-item text-danger"><i class="fas fa-times-circle mr-2"></i>Falha ao carregar irregularidades.</li>');
            })
            .finally(() => {
                hideLoadingIndicator();
                // After loading, invalidate map size to fix potential rendering issues inside modal
                 if (routeMap) {
                    // Give the modal a moment to fully open before invalidating
                     setTimeout(() => {
                         routeMap.invalidateSize();
                     }, 100);
                 }
            });
    }


    // --- Event Listeners ---

    // Handle clicks on the "VER MAPA" button in the table rows
    $('.view-route').on('click', function () {
        const routeId = $(this).data('route-id');
        console.log(`View route button clicked for ID: ${routeId}`);

        // Load route details and open the modal
        loadRouteDetails(routeId);

        // The modal is shown *after* loading data starts.
        // map.invalidateSize() is called in the modal's 'shown.bs.modal' event.
        $('#mapModal').modal('show');
    });

    // Update map size when the modal finishes showing (ensures map renders correctly)
    $('#mapModal').on('shown.bs.modal', function () {
        console.log("Map modal shown, invalidating map size...");
        if (routeMap) {
            routeMap.invalidateSize();
             // Optional: Fit bounds again after invalidating size if needed
             // This might require storing the bounds from the last data load
         }
    });

    // Clear map layers when the modal is hidden
     $('#mapModal').on('hidden.bs.modal', function () {
        console.log("Map modal hidden, clearing layers...");
        clearRouteMapLayers();
        // Optional: Reset modal content placeholders here
        $('#modalRouteName').text('');
        $('#modalAvgSpeed').text('- km/h');
        $('#modalHistoricSpeed').text('- km/h');
        $('#speedProgressBar').css('width', '0%').attr('aria-valuenow', 0).removeClass('bg-success bg-warning bg-danger');
        $('#irregularitiesList').html('<li class="list-group-item text-muted">Nenhuma irregularidade detectada neste momento nos trechos monitorados.</li>'); // Reset list
     });


    // --- Logout Button Listener ---
    // *** IMPORTANT: Change '.btn-primary' to a more specific ID or class ***
    // Example: Assuming the logout button has an ID="logoutButton"
     $('#logoutButton').on('click', function(event) {
        event.preventDefault(); // Prevent default link/button action if it's in a form/link
        console.log("Logout button clicked");
        // Call your logout function
        logout(); // Make sure the logout function is defined elsewhere and accessible
     });
     // If your logout button *must* be .btn-primary, add a more specific parent or check text
     // Example: $('.user .btn-primary').on('click', ...) or $('.btn-primary:contains("Sair")')


    // --- Removed original alert map logic (initMap, isValidLocation, #view-on-map click) ---
    // This logic seemed to conflict with the route map and the page's purpose.

    // --- Removed original row coloring logic (updateRowColors, getRowColor) ---
    // This logic seemed tied to alerts/events by date, not the route delay calculation in the HTML.
    // Table row coloring is now handled by the HTML template using Bootstrap classes.

}); // End of $(document).ready

// Note: Ensure your `logout()` function is defined and globally accessible
// or included before this script block.
// Example (placeholder):
/*
function logout() {
    console.log("Performing logout action...");
    // Implement your actual logout logic here (e.g., redirecting to logout URL, clearing session)
    window.location.href = '/logout'; // Example redirection
}
*/