# FreeRADIUS 3.2 for ISPadmin PPPoE (rlm_sql against the ISPadmin MySQL database).
FROM ubuntu:24.04

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      freeradius freeradius-mysql freeradius-utils \
 && rm -rf /var/lib/apt/lists/*

ENV RADDB=/etc/freeradius/3.0

# Only what PPPoE needs: our sql module, our two virtual servers, our clients.
# EAP / inner-tunnel and the stock default site are disabled.
COPY docker/freeradius/sql                   ${RADDB}/mods-available/sql
COPY docker/freeradius/site-ispadmin         ${RADDB}/sites-available/ispadmin
COPY docker/freeradius/site-ispadmin-clients ${RADDB}/sites-available/ispadmin-clients
COPY docker/freeradius/clients.conf          ${RADDB}/clients.conf
COPY docker/freeradius/entrypoint.sh         /usr/local/bin/freeradius-entrypoint.sh

RUN cd ${RADDB} \
 && rm -f sites-enabled/* mods-enabled/eap mods-enabled/sql \
 && ln -s ../sites-available/ispadmin sites-enabled/ispadmin \
 && ln -s ../sites-available/ispadmin-clients sites-enabled/ispadmin-clients \
 && ln -s ../mods-available/sql mods-enabled/sql \
 && chgrp -h freerad mods-available/sql clients.conf sites-available/ispadmin sites-available/ispadmin-clients \
 && chmod 640 mods-available/sql clients.conf \
 && chmod +x /usr/local/bin/freeradius-entrypoint.sh

EXPOSE 1812/udp 1813/udp
ENTRYPOINT ["/usr/local/bin/freeradius-entrypoint.sh"]
CMD ["freeradius", "-f", "-l", "stdout"]
