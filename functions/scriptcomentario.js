// ==UserScript==
// @name         Carregar Pontos KML BR-040
// @namespace    http://tampermonkey.net/
// @version      1.0
// @description  Carrega pontos de quilometragem da BR-040 como comentários
// @author       Você
// @match        https://www.waze.com/editor*
// @match        https://www.waze.com/*/editor*
// @grant        none
// @require      https://code.jquery.com/jquery-3.6.0.min.js
// ==/UserScript==

(function() {
    'use strict';

    // Aguarda o carregamento completo do WME
    const waitForWME = setInterval(() => {
        if (typeof W !== 'undefined' && W.loginManager && W.loginManager.isLoggedIn()) {
            clearInterval(waitForWME);
            initScript();
        }
    }, 1000);

    function initScript() {
        // Cria botão na barra de ferramentas
        const button = document.createElement('input');
        button.type = 'button';
        button.value = 'Carregar KM BR-040';
        button.style.cssText = 'position: fixed; top: 100px; right: 20px; z-index: 9999; padding: 10px;';
        button.addEventListener('click', processKML);
        document.body.appendChild(button);
    }

    function processKML() {
        const kmlString = `';
`;

        const parser = new DOMParser();
        const xmlDoc = parser.parseFromString(kmlString, "text/xml");
        
        // Extrai todos os Placemarks
        const placemarks = xmlDoc.getElementsByTagName('Placemark');
        
        Array.from(placemarks).forEach(placemark => {
            const name = placemark.getElementsByTagName('name')[0]?.textContent || 'Sem nome';
            const desc = placemark.getElementsByTagName('description')[0]?.textContent || '';
            const coords = placemark.getElementsByTagName('coordinates')[0]?.textContent.split(',');
            
            if (coords && coords.length >= 2) {
                const lon = parseFloat(coords[0]);
                const lat = parseFloat(coords[1]);
                
                // Cria comentário
                const comment = new W.model.Comment({
                    geometry: new W.model.GeometryPoint([lat, lon]),
                    title: `KM ${name}`,
                    description: desc
                });

                // Adiciona ao mapa
                W.model.comments.add(comment);
            }
        });

        // Atualiza a visualização
        W.map.update();
        alert('Pontos carregados com sucesso!');
    }
})();