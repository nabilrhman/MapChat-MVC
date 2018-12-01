var markers = [];
// The array where to store the markers


function initialize() {

    $.ajax({

        type: 'POST',
        dataType: 'json',
        url: '/app/get-locations',
        success: function (response) {

            var mapOptions = {
                zoom: 10,
                center: new google.maps.LatLng(43.45990000, -116.24400000),
                disableDefaultUI: true,
                styles: [
                    {
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#f5f5f5"
                            }
                        ]
                    },
                    {
                        "elementType": "labels.icon",
                        "stylers": [
                            {
                                "visibility": "off"
                            }
                        ]
                    },
                    {
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#616161"
                            }
                        ]
                    },
                    {
                        "elementType": "labels.text.stroke",
                        "stylers": [
                            {
                                "color": "#f5f5f5"
                            }
                        ]
                    },
                    {
                        "featureType": "administrative.land_parcel",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#ddbbff"
                            }
                        ]
                    },
                    {
                        "featureType": "poi",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#e7ddff"
                            }
                        ]
                    },
                    {
                        "featureType": "poi",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#5800ea"
                            }
                        ]
                    },
                    {
                        "featureType": "poi.park",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#daccff"
                            }
                        ]
                    },
                    {
                        "featureType": "poi.park",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#953eff"
                            }
                        ]
                    },
                    {
                        "featureType": "road",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#ffffff"
                            }
                        ]
                    },
                    {
                        "featureType": "road.arterial",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#757575"
                            }
                        ]
                    },
                    {
                        "featureType": "road.highway",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#dadada"
                            }
                        ]
                    },
                    {
                        "featureType": "road.highway",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#616161"
                            }
                        ]
                    },
                    {
                        "featureType": "road.local",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#9e9e9e"
                            }
                        ]
                    },
                    {
                        "featureType": "transit.line",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#e5e5e5"
                            }
                        ]
                    },
                    {
                        "featureType": "transit.station",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#eeeeee"
                            }
                        ]
                    },
                    {
                        "featureType": "water",
                        "elementType": "geometry",
                        "stylers": [
                            {
                                "color": "#c6b9ff"
                            }
                        ]
                    },
                    {
                        "featureType": "water",
                        "elementType": "labels.text.fill",
                        "stylers": [
                            {
                                "color": "#8746ff"
                            }
                        ]
                    }
                ]
            }
            var map = new google.maps.Map(document.getElementById("map"), mapOptions);


            //var locations = [{"lat":"43.45990000","lng":"-116.24400000","name":"Nabil Rahman"},{"lat":"43.45990000","lng":"-116.24400000","name":"Maruf Ahmed"},{"lat":"44.04160000","lng":"-116.97830000","name":"Prottos"},{"lat":"43.45990000","lng":"-116.24400000","name":"Nabil Rahman"},{"lat":"47.90170000","lng":"-122.24720000","name":"colby"},{"lat":"22.28330000","lng":"114.15000000","name":"Tarik"},{"lat":"37.51120000","lng":"126.97410000","name":"Da Eun Lee"},{"lat":"36.71460000","lng":"137.18980000","name":"Sabbir Ahmed"} ];
            var locations = response;


            var marker, i;
            var infowindow = new google.maps.InfoWindow();

            var bounds = new google.maps.LatLngBounds();


            google.maps.event.addListener(map, 'click', function () {
                infowindow.close();
            });


            for (i = 0; i < locations.length; i++) {
                marker = new google.maps.Marker({
                    position: new google.maps.LatLng(locations[i].lat, locations[i].lng),
                    map: map,
                    icon: 'https://image.ibb.co/k1AzU9/ic_location.png'
                });

                google.maps.event.addListener(marker, 'mouseover', (function (marker, i) {
                    return function () {
                        infowindow.setContent(locations[i].name);
                        infowindow.open(map, marker);
                    }
                })(marker, i));

                bounds.extend(marker.getPosition());
                // Push the marker to the 'markers' array
                markers.push(marker);
            }


//center the map to the geometric center of all markers

            map.fitBounds(bounds);
            map.setCenter(bounds.getCenter());

//Alternatively this code can be used to set the zoom for just 1 marker and to skip redrawing.
//Note that this will not cover the case if you have 2 markers on the same address.






        }
    });




}

/*
    for (i in arr)
    {
        var marker = new google.maps.Marker({
            position: new google.maps.LatLng(arr[i].lat, arr[i].lng),
            map: map,
            icon: 'https://image.ibb.co/k1AzU9/ic_location.png',
            markerName: arr[i].name
        });

        var infowindow = new google.maps.InfoWindow({
            content: arr[i].name
        });

        markers.push(marker);
        console.log(arr[i].name);
    }

    for (var i = 0; i < markers.length; i++) {
        google.maps.event.addListener(marker, 'click', function () {
            // where I have added .html to the marker object.
            infowindow.setContent(marker.markerName);
            infowindow.open(map, this);
        });
    }*/




 /*   console.log("Finished executing Map.js");

}*/

/*console.log(markers);*/

/*
google.maps.event.addDomListener(window, 'load', initialize);

var arr = [{"lat":"43.45990000","lng":"-116.24400000","name":"Nabil Rahman"},{"lat":"43.45990000","lng":"-116.24400000","name":"Maruf Ahmed"},{"lat":"44.04160000","lng":"-116.97830000","name":"Prottos"},{"lat":"43.45990000","lng":"-116.24400000","name":"Nabil Rahman"},{"lat":"47.90170000","lng":"-122.24720000","name":"colby"},{"lat":"22.28330000","lng":"114.15000000","name":"Tarik"},{"lat":"37.51120000","lng":"126.97410000","name":"Da Eun Lee"},{"lat":"36.71460000","lng":"137.18980000","name":"Sabbir Ahmed"} ];

var mapOptions = {
    zoom: 10,
    center: new google.maps.LatLng(23.777176, 90.399452),
    disableDefaultUI: true,
    styles : [
        {
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#f5f5f5"
                }
            ]
        },
        {
            "elementType": "labels.icon",
            "stylers": [
                {
                    "visibility": "off"
                }
            ]
        },
        {
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#616161"
                }
            ]
        },
        {
            "elementType": "labels.text.stroke",
            "stylers": [
                {
                    "color": "#f5f5f5"
                }
            ]
        },
        {
            "featureType": "administrative.land_parcel",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#ddbbff"
                }
            ]
        },
        {
            "featureType": "poi",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#e7ddff"
                }
            ]
        },
        {
            "featureType": "poi",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#5800ea"
                }
            ]
        },
        {
            "featureType": "poi.park",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#daccff"
                }
            ]
        },
        {
            "featureType": "poi.park",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#953eff"
                }
            ]
        },
        {
            "featureType": "road",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#ffffff"
                }
            ]
        },
        {
            "featureType": "road.arterial",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#757575"
                }
            ]
        },
        {
            "featureType": "road.highway",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#dadada"
                }
            ]
        },
        {
            "featureType": "road.highway",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#616161"
                }
            ]
        },
        {
            "featureType": "road.local",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#9e9e9e"
                }
            ]
        },
        {
            "featureType": "transit.line",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#e5e5e5"
                }
            ]
        },
        {
            "featureType": "transit.station",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#eeeeee"
                }
            ]
        },
        {
            "featureType": "water",
            "elementType": "geometry",
            "stylers": [
                {
                    "color": "#c6b9ff"
                }
            ]
        },
        {
            "featureType": "water",
            "elementType": "labels.text.fill",
            "stylers": [
                {
                    "color": "#8746ff"
                }
            ]
        }
    ]
}
var map = new google.maps.Map(document.getElementById("map"), mapOptions);

var markers = [];
var infowindows = [];

function initialize() {

    for (var i = 0; i < arr.length; ++i) {

        infowindows[i] = new google.maps.InfoWindow({
            content: arr[i].name
        });

        markers[i] = new google.maps.Marker({
            position: new google.maps.LatLng(
                arr[i].lat, arr[i].lng
            ),
            map: map
            // icon: fonekingiconsrc
        });

        google.maps.event.addListener(markers[i], 'click', (function (marker, i) {
            return function () {
                infowindows[i].open(map, markers[i]);
            }
        })(markers[i], i));

    }
}

*/



/*function createMarker(lat, lng, html) {
    var marker = new google.maps.Marker({
        position: new google.maps.LatLng(lat, lng),
        map: map,
        title: html
    });


    markers.push(marker);
}

function process() {
    for (var i = 0; i < arr.length; i++) {
        createMarker(arr[i].lat, arr[i].lng, arr[i].name);
        infowindows[i] = new google.maps.InfoWindow({
            content: html
        });

        google.maps.event.addListener(markers[i], 'click', (function(marker, i) {
            return function() {
                infowindows[i].open(map, markers[i]);
            }
        })(markers[i], i));
    }
}

function load() {
    initialize();
    process(arr);
}

function initialize() {

    mapOptions = {
        zoom: 10,
        center: new google.maps.LatLng(23.777176, 90.399452),
        disableDefaultUI: true,
        styles : [
            {
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#f5f5f5"
                    }
                ]
            },
            {
                "elementType": "labels.icon",
                "stylers": [
                    {
                        "visibility": "off"
                    }
                ]
            },
            {
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#616161"
                    }
                ]
            },
            {
                "elementType": "labels.text.stroke",
                "stylers": [
                    {
                        "color": "#f5f5f5"
                    }
                ]
            },
            {
                "featureType": "administrative.land_parcel",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#ddbbff"
                    }
                ]
            },
            {
                "featureType": "poi",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#e7ddff"
                    }
                ]
            },
            {
                "featureType": "poi",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#5800ea"
                    }
                ]
            },
            {
                "featureType": "poi.park",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#daccff"
                    }
                ]
            },
            {
                "featureType": "poi.park",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#953eff"
                    }
                ]
            },
            {
                "featureType": "road",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#ffffff"
                    }
                ]
            },
            {
                "featureType": "road.arterial",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#757575"
                    }
                ]
            },
            {
                "featureType": "road.highway",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#dadada"
                    }
                ]
            },
            {
                "featureType": "road.highway",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#616161"
                    }
                ]
            },
            {
                "featureType": "road.local",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#9e9e9e"
                    }
                ]
            },
            {
                "featureType": "transit.line",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#e5e5e5"
                    }
                ]
            },
            {
                "featureType": "transit.station",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#eeeeee"
                    }
                ]
            },
            {
                "featureType": "water",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#c6b9ff"
                    }
                ]
            },
            {
                "featureType": "water",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#8746ff"
                    }
                ]
            }
        ]
    }
    map = new google.maps.Map(document.getElementById("map"), mapOptions);
}

//google.maps.event.addDomListener(window, 'load', initialize);

console.log(markers);*/


/*
// The function to trigger the marker click, 'id' is the reference index to the 'markers' array.
function myClick(id){
    google.maps.event.trigger(markers[id], 'click');
}*/
