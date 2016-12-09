var WebSocketServer = require('ws').Server;
var http = require('http');
var fs = require('fs');
var pg = require('pg');
var _ = require('underscore');
var Promise = require('promise');
var YAML = require('js-yaml');
var Sequelize = require('sequelize');
var Promise = require('promise');

var ROOT_DIR = __dirname + '/../../..';
var CONFIG = {};

console.log('---------------------');
console.log('- STARTING DISPATCH -');
console.log('---------------------');

try {
  var yaml = YAML.safeLoad(fs.readFileSync(ROOT_DIR + '/app/config/parameters.yml', 'utf8'));
  CONFIG = yaml.parameters;
} catch (e) {
  console.log(e);
}

var cert = fs.readFileSync(ROOT_DIR + '/var/jwt/public.pem');

var pgPool = new pg.Pool({
  host: CONFIG.database_host,
  port: CONFIG.database_port,
  user: CONFIG.database_user,
  password: CONFIG.database_password,
  database: CONFIG.database_name,
});

var redis = require('redis').createClient();
var redisPubSub = require('redis').createClient();

var sequelize = new Sequelize(CONFIG.database_name, CONFIG.database_user, CONFIG.database_password, {
  host: CONFIG.database_host,
  dialect: 'postgres',
  logging: false,
});

var server = http.createServer(function(request, response) {
    // process HTTP request. Since we're writing just WebSockets server
    // we don't have to implement anything.
});
server.listen(8000, function() {});

var Db = require('../Db')(sequelize);

var Courier = require('../models/Courier').Courier;
Courier.init(redis, redisPubSub);

var Order = require('../models/Order').Order;
Order.init(redis, sequelize, Db);

var OrderDispatcher = require('../models/OrderDispatcher');
var orderDispatcher = new OrderDispatcher(redis, Order.Registry);

var UserService = require('../UserService');
var userService = new UserService(pgPool);

var TokenVerifier = require('../TokenVerifier');
var tokenVerifier = new TokenVerifier(cert, userService, Db);

/* Order dispatch loop */

orderDispatcher.setHandler(function(order, next) {

  console.log('Trying to dispatch order #' + order.id);

  Courier.nearestForOrder(order, 10 * 1000).then(function(courier) {

    if (!courier) {
      console.log('No couriers nearby');
      return next();
    }

    console.log('Dispatching order #' + order.id + ' to courier #' + courier.id);

    // There is a courier available
    // Change state to "DISPATCHING" and wait for feedback
    courier.setOrder(order.id);
    courier.setState(Courier.DISPATCHING);

    // Remove order from the waiting list
    redis.lrem('orders:waiting', 0, order.id, function(err) {
      if (err) throw err;
      redis.lpush('orders:dispatching', order.id, function(err) {
        if (err) throw err;
        // TODO record dispatch event ?
        courier.send({
          type: 'order',
          order: {
            id: order.id,
            restaurant: {
              latitude: order.restaurant.geo.coordinates[0],
              longitude: order.restaurant.geo.coordinates[1],
            },
            deliveryAddress: {
              latitude: order.delivery_address.geo.coordinates[0],
              longitude: order.delivery_address.geo.coordinates[1],
            }
          }
        });
        next();
      });
    });
  });
});

// Perform sanity check of Postgres vs Redis ?

Db.Order.findAll({
  where: {
    status: {$in: [Order.WAITING, Order.ACCEPTED, Order.PICKED]},
  },
  include: [Db.Restaurant, Db.DeliveryAddress]
}).then(function(orders) {

  var waiting = _.filter(orders, function(order) { return order.status === Order.WAITING });
  var delivering = _.filter(orders, function(order) { return order.status === Order.ACCEPTED || order.status === Order.PICKED });

  redis.del(['orders:waiting', 'orders:delivering'], function(err) {
    if (err) throw err;

    var geokeys = [];
    _.each(orders, function(order) {
      var deliveryAddress = {
        latitude: order.delivery_address.geo.coordinates[0],
        longitude: order.delivery_address.geo.coordinates[1],
      }
      geokeys.push(deliveryAddress.longitude);
      geokeys.push(deliveryAddress.latitude);
      geokeys.push('delivery_address:' + order.delivery_address.id);
    });

    if (geokeys.length > 0) {
      redis.geoadd('delivery_addresses:geo', geokeys);
    }

    var deliveringIds = delivering.map(function(order) {
      return order.id;
    });
    if (deliveringIds.length > 0) {
      redis.rpush('orders:delivering', deliveringIds);
    }

    var waitingIds = waiting.map(function(order) {
      return order.id;
    });
    if (waitingIds.length > 0) {
      redis.rpush('orders:waiting', waitingIds);
    }

    // TODO Start server & order loop here
    console.log('Everything is loaded, starting dispatch loop...');
    orderDispatcher.start();
  });
});

// create the server
wsServer = new WebSocketServer({
    server: server,
    verifyClient: function (info, cb) {
      tokenVerifier.verify(info, cb);
    },
});

var isClosing = false;

// WebSocket server
wsServer.on('connection', function(ws) {

    var courier = ws.upgradeReq.courier;
    courier.connect(ws);
    Courier.Pool.add(courier);

    console.log('Courier #' + courier.id + ' connected!');

    ws.on('message', function(messageText) {

      if (isClosing) {
        return;
      }

      var message = JSON.parse(messageText);

      if (message.type === 'updateCoordinates') {
        console.log('Courier ' + courier.id + ', state = ' + courier.state + ' updating position in Redis...');
        Courier.updateCoordinates(courier, message.coordinates);
      }

    });

    ws.on('close', function() {
      console.log('Courier #' + courier.id + ' disconnected!');
      Courier.Pool.remove(courier);
      console.log('Number of couriers connected: ' + Courier.Pool.size());
    });
});

// Handle restarts
process.on('SIGINT', function () {
  console.log('---------------------');
  console.log('- STOPPING DISPATCH -');
  console.log('---------------------');
  isClosing = true;
  orderDispatcher.stop();
  Courier.Pool.removeAll();
});
