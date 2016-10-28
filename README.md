# jeedom_eventghost
Jeedom eventghost plugin. 

Allow to send some command from jeedom to an eventghost.

1) Intall Eventghost : http://www.eventghost.org/

2) Install and configure the event ghost webserver plugin 
IP = W.X.Y.Z
PORT = PP

3) Add HTTP event on Eventghost action
"HTTP.id"

4) Install Eventghost plugin on jeedom

5) Configure Eventghost plugin object
Ip of eventhhost server = W.X.Y.Z
Port of eventhhost server = PP

Add command :

command name : Name of command in jeedom
command id : Name of the HTTP event in jeedom ex:id and not HTTP.id



