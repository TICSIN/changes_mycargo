/*
Changelog
----------
Amv1 - Adding Promise resolve() to fix missing promise error and long loading for login without token
 */

import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from 'src/environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AuthService {

  private _user;
  COOKIE_NAME = "CLARITY-AUTH-TOKEN";

  constructor(private http: HttpClient) { 
  }

  canActivate(): boolean {
    return true;
  }

  isLoggedIn() {
    //return true;
    console.log("LoggedIN: " + this.getCookie(this.COOKIE_NAME));
    return this.getCookie(this.COOKIE_NAME) !== null;
  }

  
  login(OTP) {

    console.log(OTP);

    let authenticationPromise;

    //If not logged in, we have to
    //to solve First time login missing Promise 
    if(!this.isLoggedIn()) {
      console.log("authenticate start");
      authenticationPromise = new Promise <any>((resolve, reject) =>{
        this.http.get(environment.authentication_server + "/authenticate/" + OTP).subscribe({
          //Amv1
          next: (response) => {this.setCookie(this.COOKIE_NAME,(response as any).token); resolve(response);},
          error: () =>{window.location.href = "https://login.ticsin.com";}
        });
      });
        
    } else {
      //If manually change the cookies to force login, Main page will load out but no data to retrieve.
      authenticationPromise = new Promise <any>((resolve, reject) => {
        //we should refresh the token here
        setTimeout( () => {
          let assoarr: {[key: string]: string} = {"token": this.getCookie(this.COOKIE_NAME)};
          //Amv1
          resolve(assoarr);
        }, 1500);
      });
    }

    return authenticationPromise;
  }

  logout() {
    this.deleteCookie(this.COOKIE_NAME);
    window.location.href = "https://login.ticsin.com";
  }

  //We should decode the value here
  public set user(value) {

    const tokens = value.split(":");
    this._user = JSON.parse(atob(tokens[0]));
  }

  public get user() {
    return this._user;
  }

  public setCookie(name: string, val: string) {
    const date = new Date();
    const value = val;

    // Set it expire in 7 days
    date.setTime(date.getTime() + (7 * 24 * 60 * 60 * 1000));

    // Set Cookie with expiry date, or else, it will be deleted after browser close. Expiry date must be in UTC
    // Set path. Tell browser which path the cookies belong to. By default it is celong to current page.
    // with path=/ it will belong to the root path of the mycargo page. it will inherit to child page
    document.cookie = name + "=" + value + "; expires=" + date.toUTCString() + "; path=/";
  }

  public getCookie(name: string) {
    const value = "; " + document.cookie;

    //No matter the token cookies name is in the first, last or middle
    //filter the pattern of "; CLARITY_AUTH-TOKEN=xxxxxxxxxxx"
    const parts = value.split("; " + name + "=");

    //The filter will always get 2 array with is before & after the "; CLARITY_AUTH-TOKEN="
    //which is array 1 "; abc=def; fff=ttt" and array 2 "[token]; anythingcookie=aaaa"
    //Pop() out the array 1 and split the with ";" if it have extra session after token and shift the last array out to get token
    if (parts.length == 2) {
      return parts.pop().split(";").shift();
    }
    return null;
  }

  get groupID() {
    return "abc";
  }

  private deleteCookie(name: string) {
    const date = new Date();

    // Set it expire in -1 days
    date.setTime(date.getTime() + (-1 * 24 * 60 * 60 * 1000));

    // Set it
    //set the expiry date to negative date for date before current will delete the cookies
    document.cookie = name + "=; expires=" + date.toUTCString() + "; path=/";
  }
}
