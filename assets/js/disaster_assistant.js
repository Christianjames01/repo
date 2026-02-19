let weatherData=null,typhoonData=[],userLocation='Philippines',userCoords={lat:8.4542,lng:124.6319},map=null,markers=[];

document.addEventListener('DOMContentLoaded',()=>{
    initMap();
    detectLocation();
    fetchTyphoons();
});

function initMap(){
    map=L.map('map').setView([12.8797,121.7740],6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
        attribution:'© OpenStreetMap',
        maxZoom:19
    }).addTo(map);
}

function detectLocation(){
    if(navigator.geolocation){
        navigator.geolocation.getCurrentPosition(async(p)=>{
            userCoords.lat=p.coords.latitude;
            userCoords.lng=p.coords.longitude;
            
            L.marker([userCoords.lat,userCoords.lng],{
                icon:L.divIcon({
                    className:'',
                    html:'<div style="background:#0d6efd;width:12px;height:12px;border-radius:50%;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3)"></div>',
                    iconSize:[12,12]
                })
            }).addTo(map).bindPopup('<strong>Your Location</strong>');
            
            map.setView([userCoords.lat,userCoords.lng],10);
            
            try{
                const r=await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${userCoords.lat}&lon=${userCoords.lng}&format=json`);
                const d=await r.json();
                if(d.address){
                    const c=d.address.city||d.address.town||d.address.municipality||d.address.village;
                    const pr=d.address.state||d.address.province||d.address.region;
                    userLocation=c&&pr?`${c}, ${pr}`:(c||pr||'Philippines');
                    document.getElementById('userLocation').textContent=userLocation;
                }
            }catch(e){
                console.log('Reverse geocoding failed',e);
            }
            fetchWeather();
        },()=>{
            document.getElementById('userLocation').textContent='Philippines';
            fetchWeather();
        });
    }else{
        fetchWeather();
    }
}

async function fetchWeather(){
    try{
        const r=await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${userCoords.lat}&longitude=${userCoords.lng}&current_weather=true&hourly=relativehumidity_2m,pressure_msl&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum&timezone=Asia/Manila&forecast_days=7`);
        const d=await r.json();
        
        if(d.current_weather){
            weatherData={
                windSpeed:d.current_weather.windspeed.toFixed(1),
                temperature:d.current_weather.temperature.toFixed(1),
                pressure:d.hourly.pressure_msl[0]?d.hourly.pressure_msl[0].toFixed(0):'N/A',
                humidity:d.hourly.relativehumidity_2m[0]||'N/A'
            };
            updateWeatherDisplay();
        }
        
        if(d.daily){
            renderForecast(d.daily);
        }
    }catch(e){
        console.log('Weather fetch failed',e);
        weatherData={windSpeed:'15.0',temperature:'28.0',pressure:'1012',humidity:'75'};
        updateWeatherDisplay();
    }
}

function renderForecast(daily){
    const days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const icons=['☀️','🌤️','⛅','🌧️','⛈️'];
    let html='';
    
    for(let i=0;i<7;i++){
        const date=new Date(daily.time[i]);
        const dayName=i===0?'Today':(i===1?'Tomorrow':days[date.getDay()]);
        const maxTemp=Math.round(daily.temperature_2m_max[i]);
        const minTemp=Math.round(daily.temperature_2m_min[i]);
        const precip=daily.precipitation_sum[i]||0;
        const precipProb=daily.precipitation_probability_max[i]||0;
        
        let icon=icons[0];
        if(precip>10)icon=icons[4];
        else if(precip>5)icon=icons[3];
        else if(precipProb>50)icon=icons[2];
        else if(precipProb>20)icon=icons[1];
        
        html+=`<div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:8px;padding:1.5rem;text-align:center;transition:all 0.2s;cursor:pointer" onmouseover="this.style.borderColor='#0d6efd';this.style.background='#f0f8ff'" onmouseout="this.style.borderColor='#e4e6eb';this.style.background='#f8f9fa'">
            <div style="font-size:0.875rem;font-weight:600;color:#65676b;margin-bottom:0.75rem">${dayName}</div>
            <div style="font-size:3rem;margin:1rem 0">${icon}</div>
            <div style="font-size:1.5rem;font-weight:700;color:#1c1e21;margin-bottom:0.25rem">${maxTemp}°C</div>
            <div style="font-size:1rem;color:#65676b;margin-bottom:0.75rem">${minTemp}°C</div>
            <div style="display:flex;align-items:center;justify-content:center;gap:0.5rem;font-size:0.875rem">
                <span style="color:#0d6efd;font-weight:600">💧 ${precipProb}%</span>
                <span style="color:#65676b">|</span>
                <span style="color:#65676b">${precip.toFixed(1)}mm</span>
            </div>
        </div>`;
    }
    
    document.getElementById('forecastDays').innerHTML=html;
}

function updateWeatherDisplay(){
    document.getElementById('windSpeed').textContent=weatherData.windSpeed+' km/h';
    document.getElementById('temperature').textContent=weatherData.temperature+'°C';
    document.getElementById('pressure').textContent=weatherData.pressure+' hPa';
    document.getElementById('humidity').textContent=weatherData.humidity+'%';
}

async function fetchTyphoons(){
    try{
        const responses = await Promise.allSettled([
            fetch('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red').then(r=>r.text()),
            fetch('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC').then(r=>r.text())
        ]);
        
        typhoonData=[];
        markers.forEach(m=>map.removeLayer(m));
        markers=[];
        
        let gdacsText = '';
        if(responses[0].status==='fulfilled' && responses[0].value){
            gdacsText = responses[0].value;
        } else if(responses[1].status==='fulfilled' && responses[1].value){
            gdacsText = responses[1].value;
        }
        
        if(gdacsText && gdacsText.includes('<item>')){
            const parser=new DOMParser();
            const xml=parser.parseFromString(gdacsText,'text/xml');
            const items=xml.querySelectorAll('item');
            
            items.forEach((item,index)=>{
                if(index>=5) return;
                
                const title=item.querySelector('title')?.textContent||'';
                const desc=item.querySelector('description')?.textContent||'';
                const point=item.querySelector('point')?.textContent||'';
                
                if(point){
                    const[lat,lng]=point.split(' ').map(Number);
                    
                    // Check if typhoon is near Philippines (latitude 4-21, longitude 116-127)
                    if(lat<4 || lat>21 || lng<116 || lng>127) return;
                    
                    const windMatch=desc.match(/(\d+)\s*km\/h/i)||desc.match(/(\d+)\s*kts/i)||desc.match(/wind.*?(\d+)/i);
                    let windSpeed=windMatch?parseInt(windMatch[1]):0;
                    
                    if(desc.toLowerCase().includes('kts') || desc.toLowerCase().includes('knot')){
                        windSpeed=Math.round(windSpeed*1.852);
                    }
                    
                    const nameMatch=title.match(/Typhoon\s+(\w+)/i)||title.match(/Storm\s+(\w+)/i)||title.match(/TC\s+(\w+)/i)||title.match(/Tropical.*?(\w+)/i)||desc.match(/name[:\s]+(\w+)/i);
                    const name=nameMatch?nameMatch[1]:'Tropical System';
                    
                    const dist=calculateDistance(userCoords.lat,userCoords.lng,lat,lng);
                    
                    typhoonData.push({
                        name:name,
                        lat:lat,
                        lng:lng,
                        windSpeed:windSpeed||95,
                        distance:Math.round(dist)
                    });
                }
            });
        }
        
        typhoonData.sort((a,b)=>a.distance-b.distance);
        updateTyphoonList();
        addTyphoonMarkers();
        
    }catch(e){
        console.error('Typhoon fetch error:',e);
        document.getElementById('typhoonList').innerHTML='<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div><div style="font-size:0.75rem;color:#65676b;margin-top:0.5rem">Last checked: '+new Date().toLocaleTimeString()+'</div></div>';
    }
}

function calculateDistance(lat1,lon1,lat2,lon2){
    const R=6371;
    const dLat=(lat2-lat1)*Math.PI/180;
    const dLon=(lon2-lon1)*Math.PI/180;
    const a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)*Math.sin(dLon/2);
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function updateTyphoonList(){
    const list=document.getElementById('typhoonList');
    
    if(typhoonData.length===0){
        list.innerHTML='<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div><div style="font-size:0.75rem;color:#65676b;margin-top:0.5rem">All clear in your area</div></div>';
        return;
    }
    
    let html='';
    typhoonData.forEach(t=>{
        const severity=t.distance<300?'danger':(t.distance<600?'warning':'info');
        const badgeClass=t.distance<300?'badge-danger':(t.distance<600?'badge-warning':'badge-info');
        const badgeText=t.distance<300?'⚠️ VERY CLOSE':(t.distance<600?'⚠️ CLOSE':'ℹ️ MONITORING');
        
        html+=`<div class="typhoon-item ${severity}" onclick="focusTyphoon(${t.lat},${t.lng})">
            <span class="badge ${badgeClass}">${badgeText}</span>
            <div class="typhoon-name">${t.name}</div>
            <div class="typhoon-details">
                <div class="detail-item">
                    <div class="detail-label">Wind Speed</div>
                    <div class="detail-value">${t.windSpeed} km/h</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Distance</div>
                    <div class="detail-value">${t.distance} km</div>
                </div>
            </div>
        </div>`;
    });
    list.innerHTML=html;
}

function addTyphoonMarkers(){
    typhoonData.forEach(t=>{
        const color=t.distance<300?'#dc3545':(t.distance<600?'#ffc107':'#0d6efd');
        
        const marker=L.marker([t.lat,t.lng],{
            icon:L.divIcon({
                className:'',
                html:`<div style="background:${color};width:22px;height:22px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;font-size:12px">🌀</div>`,
                iconSize:[22,22]
            })
        }).addTo(map).bindPopup(`<strong>${t.name}</strong><br>${t.windSpeed} km/h winds<br>${t.distance} km away`);
        markers.push(marker);
        
        const circle=L.circle([t.lat,t.lng],{
            color:color,
            fillColor:color,
            fillOpacity:0.1,
            radius:t.distance*1000,
            weight:2
        }).addTo(map);
        markers.push(circle);
    });
}

function focusTyphoon(lat,lng){
    map.setView([lat,lng],8,{animate:true});
}

async function sendMessage(){
    const input=document.getElementById('messageInput');
    const msg=input.value.trim();
    if(!msg)return;
    
    addMessageToChat('user',msg);
    input.value='';
    
    const sendBtn=document.getElementById('sendBtn');
    sendBtn.disabled=true;
    input.disabled=true;
    
    showLoading();
    
    try{
        const response=await fetch(window.location.href,{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                message:msg,
                weatherData:weatherData,
                typhoonData:typhoonData,
                userLocation:userLocation
            })
        });
        
        const data=await response.json();
        hideLoading();
        
        if(data.success){
            addMessageToChat('assistant',data.response);
            const lastMessage=document.querySelector('.message.assistant:last-child .message-bubble');
            const badge=document.createElement('div');
            badge.className=`ai-badge ${data.isRealAI?'real':'fallback'}`;
            badge.textContent=data.isRealAI?'🤖 AI':'💬 Smart';
            lastMessage.appendChild(badge);
        }else{
            addMessageToChat('assistant','Sorry, an error occurred. Please try again.');
        }
    }catch(e){
        hideLoading();
        addMessageToChat('assistant','Connection error. Please check your internet and try again.');
    }finally{
        sendBtn.disabled=false;
        input.disabled=false;
        input.focus();
    }
}

function askQuestion(question){
    document.getElementById('messageInput').value=question;
    sendMessage();
}

function addMessageToChat(role,content){
    const container=document.getElementById('chatContainer');
    const message=document.createElement('div');
    message.className=`message ${role}`;
    
    const bubble=document.createElement('div');
    bubble.className='message-bubble';
    bubble.textContent=content;
    
    message.appendChild(bubble);
    container.appendChild(message);
    container.scrollTop=container.scrollHeight;
    
    if(role==='user'){
        detectLocationInMessage(content);
    }
}

function detectLocationInMessage(msg){
    const locations={
        'manila':{lat:14.5995,lng:120.9842,zoom:11},'metro manila':{lat:14.5995,lng:120.9842,zoom:10},
        'quezon city':{lat:14.6760,lng:121.0437,zoom:12},'makati':{lat:14.5547,lng:121.0244,zoom:13},
        'pasig':{lat:14.5764,lng:121.0851,zoom:13},'taguig':{lat:14.5176,lng:121.0509,zoom:13},
        'cebu':{lat:10.3157,lng:123.8854,zoom:12},'cebu city':{lat:10.3157,lng:123.8854,zoom:12},
        'davao':{lat:7.1907,lng:125.4553,zoom:12},'davao city':{lat:7.1907,lng:125.4553,zoom:12},
        'cagayan de oro':{lat:8.4542,lng:124.6319,zoom:12},'cdo':{lat:8.4542,lng:124.6319,zoom:12},
        'baguio':{lat:16.4023,lng:120.5960,zoom:13},'iloilo':{lat:10.7202,lng:122.5621,zoom:12},
        'bacolod':{lat:10.6760,lng:122.9510,zoom:12},'tacloban':{lat:11.2447,lng:125.0037,zoom:12},
        'zamboanga':{lat:6.9214,lng:122.0790,zoom:12},'general santos':{lat:6.1164,lng:125.1716,zoom:12},
        'butuan':{lat:8.9475,lng:125.5406,zoom:13},'tagaytay':{lat:14.1102,lng:120.9601,zoom:13},
        'boracay':{lat:11.9674,lng:121.9248,zoom:13},'palawan':{lat:9.8349,lng:118.7384,zoom:8},
        'legazpi':{lat:13.1391,lng:123.7436,zoom:12},'naga':{lat:13.6218,lng:123.1948,zoom:13},
        'dumaguete':{lat:9.3068,lng:123.3054,zoom:13},'vigan':{lat:17.5747,lng:120.3869,zoom:13},
        'laoag':{lat:18.1987,lng:120.5942,zoom:13},'dagupan':{lat:16.0433,lng:120.3339,zoom:13},
        'batangas':{lat:13.7565,lng:121.0583,zoom:13},'angeles':{lat:15.1450,lng:120.5887,zoom:13},
        'antipolo':{lat:14.5864,lng:121.1758,zoom:12},'iligan':{lat:8.2280,lng:124.2453,zoom:13},
        'koronadal':{lat:6.5008,lng:124.8469,zoom:13},'tuguegarao':{lat:17.6132,lng:121.7270,zoom:13},
        'cabanatuan':{lat:15.4859,lng:120.9672,zoom:13},'puerto princesa':{lat:9.7392,lng:118.7353,zoom:12},
        'tagum':{lat:7.4478,lng:125.8078,zoom:13},'ormoc':{lat:11.0064,lng:124.6075,zoom:13}
    };
    
    const msgLower=msg.toLowerCase();
    for(const[place,coords]of Object.entries(locations)){
        if(msgLower.includes(place)){
            setTimeout(()=>{
                map.setView([coords.lat,coords.lng],coords.zoom,{animate:true,duration:1.5});
                const locationMarker=L.marker([coords.lat,coords.lng],{
                    icon:L.divIcon({
                        className:'',
                        html:'<div style="background:#ffc107;width:16px;height:16px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);animation:pulse 2s infinite"></div><style>@keyframes pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.3);opacity:0.7}}</style>',
                        iconSize:[16,16]
                    })
                }).addTo(map).bindPopup(`<strong>${place.charAt(0).toUpperCase()+place.slice(1)}</strong>`).openPopup();
                markers.push(locationMarker);
            },500);
            break;
        }
    }
}

function showLoading(){
    const container=document.getElementById('chatContainer');
    const loading=document.createElement('div');
    loading.className='message assistant';
    loading.id='loadingMessage';
    loading.innerHTML='<div class="loading active"><div class="loading-dots"><span></span><span></span><span></span></div></div>';
    container.appendChild(loading);
    container.scrollTop=container.scrollHeight;
}

function hideLoading(){
    const loading=document.getElementById('loadingMessage');
    if(loading)loading.remove();
}

setInterval(fetchWeather,300000);
setInterval(fetchTyphoons,600000);